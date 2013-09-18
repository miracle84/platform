<?php

namespace Oro\Bundle\InstallerBundle\Process;

use Symfony\Component\DependencyInjection\ContainerAware;

use Sylius\Bundle\FlowBundle\Process\Builder\ProcessBuilderInterface;
use Sylius\Bundle\FlowBundle\Process\Scenario\ProcessScenarioInterface;

class InstallerScenario extends ContainerAware implements ProcessScenarioInterface
{
    public function build(ProcessBuilderInterface $builder)
    {
        $builder
            ->add('welcome', new Step\WelcomeStep())
            ->add('check', new Step\CheckStep())
            ->add('configure', new Step\ConfigureStep())
            ->add('schema', new Step\SchemaStep())
            ->add('setup', new Step\SetupStep())
            ->add('final', new Step\FinalStep())
            ->setRedirect('oro_default');
    }
}
