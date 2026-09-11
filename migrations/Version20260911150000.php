<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute article.publie pour permettre de préparer un article sans le rendre
 * visible sur le site (brouillon). Rétrocompatible : la colonne est ajoutée
 * avec la valeur par défaut 1 (publié), donc tous les articles existants
 * restent visibles sans aucune action supplémentaire.
 */
final class Version20260911150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add article.publie (draft/published flag), defaulted to published so existing articles stay visible';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article ADD publie TINYINT(1) NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article DROP publie');
    }
}
