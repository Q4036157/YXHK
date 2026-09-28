<?php

declare(strict_types=1);

namespace Yxhk\NativeWindows;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\Process\Process;
use Symfonycasts\SassBundle\SassBinary;
use Symfonycasts\SassBundle\SassBuilder;

final class WindowsAssetsBundle extends Bundle implements CompilerPassInterface
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass($this);
    }

    public function process(ContainerBuilder $container): void
    {
        if ('Windows' === PHP_OS_FAMILY && $container->hasDefinition('sass.builder')) {
            $container->getDefinition('sass.builder')->setClass(WindowsSassBuilder::class);
        }
    }
}

final class WindowsSassBuilder extends SassBuilder
{
    public function __construct(
        array $sassPaths,
        string $cssPath,
        private readonly string $nativeProjectRoot,
        private readonly ?string $nativeBinaryPath,
        bool|array $sassOptions = [],
    ) {
        parent::__construct($sassPaths, $cssPath, $nativeProjectRoot, $nativeBinaryPath, $sassOptions);
    }

    public function runBuild(bool $watch): Process
    {
        if ('Windows' !== PHP_OS_FAMILY) {
            return parent::runBuild($watch);
        }
        $binary = $this->nativeBinaryPath ?? $this->nativeProjectRoot.'/var/dart-sass/sass.bat';
        if (!is_file($binary) && null === $this->nativeBinaryPath) {
            (new SassBinary($this->nativeProjectRoot.'/var'))->downloadExecutable();
        }
        if (!is_file($binary)) {
            throw new \RuntimeException('Windows Sass executable is missing.');
        }
        // Sass needs neither Mautic's large config environment nor customer secrets.
        $inherited = array_merge($_SERVER, $_ENV, getenv() ?: []);
        $environment = array_fill_keys(array_keys($inherited), false);
        foreach ($inherited as $key => $value) {
            if (in_array(strtoupper((string) $key), ['PATH', 'PATHEXT', 'SYSTEMROOT', 'WINDIR', 'COMSPEC', 'TEMP', 'TMP', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA'], true)) {
                $environment[$key] = $value;
            }
        }
        $arguments = [$binary, ...$this->getScssCssTargets(), ...$this->getBuildOptions(['--watch' => $watch])];
        $process = new Process($arguments, $this->nativeProjectRoot, $environment);
        $process->setTimeout($watch ? null : 300);
        $process->start();

        return $process;
    }
}
