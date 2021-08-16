<?php
declare(strict_types = 1);

namespace Helmich\Schema2Class\Generator;

use Helmich\Schema2Class\Codegen\PropertyGenerator;
use Helmich\Schema2Class\Generator\Property\PropertyCollection;
use Helmich\Schema2Class\Writer\WriterInterface;
use Laminas\Code\Generator\DocBlockGenerator;
use Symfony\Component\Console\Output\OutputInterface;
use Laminas\Code\DeclareStatement;
use Laminas\Code\Generator\ClassGenerator;
use Laminas\Code\Generator\DocBlock\Tag\GenericTag;
use Laminas\Code\Generator\FileGenerator;

class SchemaToClass
{
    private WriterInterface $writer;
    private OutputInterface $output;

    public function __construct(WriterInterface $writer, OutputInterface $output)
    {
        $this->writer = $writer;
        $this->output = $output;
    }

    /**
     * @param GeneratorRequest $req
     * @throws GeneratorException
     */
    public function schemaToClass(GeneratorRequest $req): void
    {
        $schema = $req->getSchema();
        $schemaProperty = new PropertyGenerator("schema", $schema, PropertyGenerator::FLAG_PRIVATE | PropertyGenerator::FLAG_STATIC);
        $schemaProperty->setDocBlock(new DocBlockGenerator(
            "Schema used to validate input for creating instances of this class",
            null,
            [new GenericTag("var", "array")]
        ));

        if ($req->isAtLeastPHP("7.4")) {
            $schemaProperty->setTypeHint("array");
        }

        $properties = [$schemaProperty];
        $methods = [];

        if (!isset($schema["properties"])) {
            throw new GeneratorException("cannot generate class for types other than 'object'");
        }

        $propertiesFromSchema = new PropertyCollection();

        foreach ($schema["properties"] as $key => $definition) {
            $isRequired = isset($schema["required"]) && in_array($key, $schema["required"]);

            $property = PropertyBuilder::buildPropertyFromSchema($req, $key, $definition, $isRequired);
            $propertiesFromSchema->add($property);
        }

        foreach ($propertiesFromSchema as $property) {
            $property->generateSubTypes($this);
        }

        $codeGenerator = new Generator($req);

        $methods[] = $codeGenerator->generateConstructor($propertiesFromSchema);

        $properties = array_merge($properties, $codeGenerator->generateProperties($propertiesFromSchema));
        $methods = array_merge($methods, $codeGenerator->generateGetterMethods($propertiesFromSchema));
        $methods = array_merge($methods, $codeGenerator->generateSetterMethods($propertiesFromSchema));

        $methods[] = $codeGenerator->generateBuildMethod($propertiesFromSchema);
        $methods[] = $codeGenerator->generateToJSONMethod($propertiesFromSchema);
        $methods[] = $codeGenerator->generateValidateMethod();
        $methods[] = $codeGenerator->generateCloneMethod($propertiesFromSchema);

        $this->writeClass(
            $req->getTargetNamespace() . '\Machine', 
            '_' . $req->getTargetClass(),
            null, 
            $properties, 
            $methods,
            $req->getTargetDirectory() . '/Machine',
            $req->isAtLeastPHP("7.0") && !$req->getOptions()->getDisableStrictTypes(),
            true
        );
        $this->writeClass(
            $req->getTargetNamespace(), 
            $req->getTargetClass(), 
            $req->getTargetNamespace() . '\Machine\_' . $req->getTargetClass(),
            [], 
            [],
            $req->getTargetDirectory(),
            $req->isAtLeastPHP("7.0") && !$req->getOptions()->getDisableStrictTypes(),
            false
        );
    }

    private function writeClass(
        string $namespace, 
        string $className, 
        ?string $extends, 
        array $properties, 
        array $methods, 
        string $directory, 
        bool $strict, 
        bool $overwrite
    ): void
    {
        $cls = new ClassGenerator(
            $className,
            $namespace,
            null,
            $extends,
            [],
            $properties,
            $methods,
            null
        );
        
        $file = new FileGenerator();
        $file->setClasses([$cls]);

        if ($strict) {
            $file->setDeclares([DeclareStatement::strictTypes(1)]);
        }

        $content = $file->generate();

        // Do some corrections because the Zend code generation library is stupid.
        $content = preg_replace('/ : \\\\self/', ' : self', $content);
        $content = preg_replace('/\\\\'.preg_quote($namespace).'\\\\/', '', $content);

        $this->writer->writeFile($directory . '/' . $className . '.php', $content, $overwrite);
    }
}
