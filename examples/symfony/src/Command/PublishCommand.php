<?php

declare(strict_types=1);

namespace App\Command;

use App\VO\RawMessage;
use CrazyGoat\TheConsoomer\AmqpStamp;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:publish', description: 'Publish some message')]
class PublishCommand extends Command
{
    public function __construct(private readonly MessageBusInterface $messageBus)
    {
        parent::__construct();
    }
    protected function configure()
    {
        parent::configure();
        $this->addArgument('message', InputArgument::REQUIRED);
        $this->addOption('size', 's', InputOption::VALUE_REQUIRED, 'The number of the message', 1);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach (range(1, $input->getOption('size')) as $i) {
            $message = new RawMessage($input->getArgument('message').': '.$i);
            $this->messageBus->dispatch(new Envelope($message, [new AmqpStamp('test')]));
        }
        return self::SUCCESS;
    }
}
