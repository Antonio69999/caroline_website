<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute media.position pour permettre un tri manuel des photos au sein d'un
 * article. Rétrocompatible : la colonne est ajoutée avec une valeur par
 * défaut puis chaque photo reçoit son rang actuel (ordre d'insertion, par
 * article) pour que rien ne change visuellement au premier déploiement.
 */
final class Version20260911123200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add media.position for admin-controlled photo ordering within an article, backfilled from existing insertion order';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media ADD position INT NOT NULL DEFAULT 0');
        $this->addSql(<<<'SQL'
            UPDATE media m
            JOIN (
                SELECT m2.id,
                       (
                           SELECT COUNT(*)
                           FROM media m3
                           WHERE m3.article_id <=> m2.article_id
                             AND m3.id < m2.id
                       ) AS computed_position
                FROM media m2
            ) ranked ON ranked.id = m.id
            SET m.position = ranked.computed_position
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media DROP position');
    }
}
