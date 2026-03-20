<?php

namespace App\Command;

use App\Entity\Article;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:init-article-positions')]
class InitArticlePositionsCommand extends Command
{
  public function __construct(private EntityManagerInterface $em)
  {
    parent::__construct();
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $articles = $this->em->getRepository(Article::class)->findBy([], ['creeLe' => 'ASC']);

    foreach ($articles as $index => $article) {
      if ($article->getPosition() === null || $article->getPosition() === 0) {
        $article->setPosition($index);
      }
    }

    $this->em->flush();
    $output->writeln('✅ Positions initialisées pour ' . count($articles) . ' articles');

    return Command::SUCCESS;
  }
}
