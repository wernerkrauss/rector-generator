<?php

declare(strict_types=1);

namespace Rector\RectorGenerator;

use PhpParser\BuilderHelpers;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\PrettyPrinter\Standard;
use Rector\RectorGenerator\NodeFactory\ConfigurationNodeFactory;
use Rector\RectorGenerator\NodeFactory\ConfigureClassMethodFactory;
use Rector\RectorGenerator\NodeFactory\NodeFactory;
use Rector\RectorGenerator\ValueObject\NamePattern;
use Rector\RectorGenerator\ValueObject\RectorRecipe;

final class TemplateVariablesFactory
{
    /**
     * @var string
     */
    private const SELF = 'self';

    /**
     * @var string
     */
    private const VARIABLE_PACKAGE = '__Package__';

    public function __construct(
        private readonly Standard $standard,
        private readonly ConfigurationNodeFactory $configurationNodeFactory,
        private readonly ConfigureClassMethodFactory $configureClassMethodFactory,
        private readonly NodeFactory $nodeFactory,
        private readonly TemplateFactory $templateFactory
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function createFromRectorRecipe(RectorRecipe $rectorRecipe): array
    {
        $data = [
            self::VARIABLE_PACKAGE => $rectorRecipe->getPackage(),
            '__Category__' => $rectorRecipe->getCategory(),
            '__Description__' => $rectorRecipe->getDescription(),
            '__Name__' => $rectorRecipe->getName(),
            '__CodeBefore__' => trim($rectorRecipe->getCodeBefore()) . PHP_EOL,
            '__CodeBeforeExample__' => $this->createCodeForDefinition($rectorRecipe->getCodeBefore()),
            '__CodeAfter__' => trim($rectorRecipe->getCodeAfter()) . PHP_EOL,
            '__CodeAfterExample__' => $this->createCodeForDefinition($rectorRecipe->getCodeAfter()),
            '__Resources__' => $this->createSourceDocBlock($rectorRecipe->getResources()),
        ];

        $rectorClass = $this->templateFactory->create(NamePattern::RECTOR_FQN_NAME_PATTERN, $data);

        if ($rectorRecipe->getConfiguration() !== []) {
            $data['__TestRuleConfiguration__'] = $this->createRuleConfiguration(
                $rectorClass,
                $rectorRecipe->getConfiguration()
            );
            $data['__RuleConfiguration__'] = $this->createRuleConfiguration(
                self::SELF,
                $rectorRecipe->getConfiguration()
            );

            $data['__ConfigurationProperties__'] = $this->createConfigurationProperty(
                $rectorRecipe->getConfiguration()
            );

            $data['__ConfigurationConstants__'] = $this->createConfigurationConstants(
                $rectorRecipe->getConfiguration()
            );

            $data['__ConfigureClassMethod__'] = $this->createConfigureClassMethod(
                $rectorRecipe->getConfiguration()
            );
        }

        if ($rectorRecipe->getExtraFileContent() !== null && $rectorRecipe->getExtraFileName() !== null) {
            $data['__ExtraFileName__'] = $rectorRecipe->getExtraFileName();
            $data['__ExtraFileContent__'] = trim($rectorRecipe->getExtraFileContent()) . PHP_EOL;
            $data['__ExtraFileContentExample__'] = $this->createCodeForDefinition(
                $rectorRecipe->getExtraFileContent()
            );
        }

        $data['__NodeTypesPhp__'] = $this->createNodeTypePhp($rectorRecipe);
        $data['__NodeTypesDoc__'] = '\\' . implode('|\\', $rectorRecipe->getNodeTypes());

        return $data;
    }

    private function createCodeForDefinition(string $code): string
    {
        if (\str_contains($code, PHP_EOL)) {
            // multi lines
            return sprintf("<<<'CODE_SAMPLE'%s%s%sCODE_SAMPLE%s", PHP_EOL, $code, PHP_EOL, PHP_EOL);
        }

        // single line
        return "'" . str_replace("'", '"', $code) . "'";
    }

    /**
     * @param string[] $source
     */
    private function createSourceDocBlock(array $source): string
    {
        if ($source === []) {
            return '';
        }

        $sourceAsString = '';
        foreach ($source as $singleSource) {
            $sourceAsString .= ' * @changelog ' . $singleSource . PHP_EOL;
        }

        $sourceAsString .= ' *';

        return rtrim($sourceAsString);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function createRuleConfiguration(string $rectorClass, array $configuration): string
    {
        $arrayItems = [];
        foreach ($configuration as $constantName => $variableConfiguration) {
            $constantName = strtoupper($constantName);

            $class = $rectorClass === self::SELF ? new Name(self::SELF) : new FullyQualified($rectorClass);
            $classConstFetch = new ClassConstFetch($class, $constantName);

            if (is_array($variableConfiguration)) {
                $variableConfiguration = $this->nodeFactory->createArray($variableConfiguration);
            } else {
                $variableConfiguration = BuilderHelpers::normalizeValue($variableConfiguration);
            }

            $arrayItems[] = new ArrayItem($variableConfiguration, $classConstFetch);
        }

        $array = new Array_($arrayItems);
        return $this->standard->prettyPrintExpr($array);
    }

    /**
     * @param array<string, mixed> $ruleConfiguration
     */
    private function createConfigurationProperty(array $ruleConfiguration): string
    {
        $properties = $this->configurationNodeFactory->createProperties($ruleConfiguration);
        return $this->standard->prettyPrint($properties);
    }

    /**
     * @param array<string, mixed> $ruleConfiguration
     */
    private function createConfigurationConstants(array $ruleConfiguration): string
    {
        $configurationConstants = $this->configurationNodeFactory->createConfigurationConstants($ruleConfiguration);
        return $this->standard->prettyPrint($configurationConstants);
    }

    /**
     * @param array<string, mixed> $ruleConfiguration
     */
    private function createConfigureClassMethod(array $ruleConfiguration): string
    {
        $classMethod = $this->configureClassMethodFactory->create($ruleConfiguration);
        return $this->standard->prettyPrint([$classMethod]);
    }

    private function createNodeTypePhp(RectorRecipe $rectorRecipe): string
    {
        $referencingClassConsts = [];
        foreach ($rectorRecipe->getNodeTypes() as $nodeType) {
            $referencingClassConsts[] = $this->nodeFactory->createClassConstReference($nodeType);
        }

        $array = $this->nodeFactory->createArray($referencingClassConsts);
        return $this->standard->prettyPrintExpr($array);
    }
}
