<?php

namespace App\Command;

use App\Entity\Media;
use App\Service\Thumbnailer;
use App\Service\ThumbnailStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * À lancer une fois sur le serveur après la mise en ligne : fabrique les vignettes des photos
 * déjà envoyées. Les nouvelles photos reçoivent leur vignette automatiquement à l'envoi.
 * Sans danger si on le relance : une vignette qui existe déjà est laissée telle quelle.
 */
#[AsCommand(
  name: 'app:images:thumbnails',
  description: 'Fabrique les vignettes (petites copies) des photos déjà envoyées',
)]
class GenerateThumbnailsCommand extends Command
{
  public function __construct(
    private readonly EntityManagerInterface $em,
    private readonly Thumbnailer $thumbnailer,
  ) {
    parent::__construct();
  }

  protected function configure(): void
  {
    $this->addOption('force', null, InputOption::VALUE_NONE, 'Refait aussi les vignettes qui existent déjà');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $io = new SymfonyStyle($input, $output);

    if (!$this->thumbnailer->isAvailable()) {
      $io->error([
        "L'extension PHP « GD » n'est pas installée sur ce serveur : impossible de fabriquer des vignettes.",
        'Le site continue de fonctionner normalement avec les photos d\'origine.',
        "Pour en profiter, demandez à l'hébergeur d'activer GD (paquet « php-gd »), puis relancez cette commande.",
      ]);

      return Command::FAILURE;
    }

    $names = $this->em->createQueryBuilder()
      ->select('DISTINCT m.imageName')
      ->from(Media::class, 'm')
      ->where('m.imageName IS NOT NULL')
      ->getQuery()
      ->getSingleColumnResult();

    $counts = [];
    $problems = [];

    foreach ($io->progressIterate($names) as $name) {
      $status = $this->thumbnailer->generate((string) $name, (bool) $input->getOption('force'));
      $counts[$status->value] = ($counts[$status->value] ?? 0) + 1;

      if (ThumbnailStatus::Failed === $status || ThumbnailStatus::Missing === $status) {
        $problems[] = sprintf('%s (%s)', $name, ThumbnailStatus::Failed === $status ? 'illisible ou trop grande' : 'fichier introuvable');
      }
    }

    $labels = [
      ThumbnailStatus::Created->value => 'Vignettes fabriquées',
      ThumbnailStatus::Exists->value => 'Déjà faites',
      ThumbnailStatus::NotNeeded->value => 'Photos déjà petites ou légères (pas de vignette utile)',
      ThumbnailStatus::UnsupportedFormat->value => 'Format non réduit (la photo d\'origine est utilisée)',
      ThumbnailStatus::Missing->value => 'Fichiers introuvables',
      ThumbnailStatus::Failed->value => 'Échecs',
    ];

    $io->newLine();
    $io->definitionList(...array_map(
      static fn (string $key, string $label): array => [$label => (string) ($counts[$key] ?? 0)],
      array_keys($labels),
      $labels,
    ));

    if ([] !== $problems) {
      $io->warning(array_merge(['À vérifier (le site utilise la photo d\'origine pour celles-ci) :'], array_slice($problems, 0, 20)));
    } else {
      $io->success(sprintf('%d photo(s) examinée(s).', count($names)));
    }

    return Command::SUCCESS;
  }
}
