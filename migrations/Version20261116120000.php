<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute l'année (facultative) d'un article : les pages des catégories rangent leurs
 * articles par année, la plus récente d'abord, sous un intertitre par année.
 * Purement additive : la colonne est vide pour les articles existants, qui gardent
 * donc exactement leur ordre actuel tant que Caroline ne leur donne pas d'année.
 */
final class Version20261116120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional article.annee (year), used to group a category\'s articles by year';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article ADD annee SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article DROP annee');
    }
}
