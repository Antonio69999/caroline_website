<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute categorie.position pour permettre de réordonner les catégories dans
 * l'admin (cet ordre pilote l'affichage dans la sidebar du site). Backfillée
 * à partir de l'id pour que l'ordre actuel des catégories ne change pas tant
 * que Caroline n'a pas glissé-déposé quoi que ce soit.
 */
final class Version20260923120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add categorie.position (drag & drop ordering in admin, drives sidebar order), backfilled from id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE categorie ADD position INT NOT NULL DEFAULT 0');
        $this->addSql('UPDATE categorie SET position = id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE categorie DROP position');
    }
}
