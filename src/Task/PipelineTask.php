<?php

namespace Otago\SharedCmsSync\Task;

use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Runs a list of download or sync services in order and reports what each one
 * did.
 *
 * A stage that throws is reported and the run carries on. These pipelines are
 * long and mostly independent, and stopping at the first failure leaves the
 * site in a state where some datasets are today's and some are last week's,
 * which is harder to reason about than one dataset that plainly did not run.
 */
abstract class PipelineTask extends BuildTask
{
    /**
     * Label => service. Each service needs run() and setReporter().
     * @return array<string, object>
     */
    abstract protected function getServices(): array;

    /**
     * @param InputInterface $input
     * @param PolyOutput     $output
     * @return int
     */
    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $failures = 0;

        foreach ($this->getServices() as $label => $service) {
            $output->writeln("--- {$label}");

            $service->setReporter(function (string $message) use ($output) {
                $output->writeln("    {$message}");
            });

            try {
                $tally = $service->run();
                $output->writeln('    ' . $this->summarise($tally));
            } catch (\Throwable $e) {
                $failures++;
                $output->writeln('    FAILED: ' . $e->getMessage());
            }
        }

        if ($failures) {
            $output->writeln("{$failures} stage(s) failed.");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param array $tally
     * @return string
     */
    protected function summarise(array $tally): string
    {
        $parts = [];
        foreach ($tally as $key => $count) {
            $parts[] = "{$key}: {$count}";
        }

        return $parts ? implode(', ', $parts) : 'done';
    }
}
