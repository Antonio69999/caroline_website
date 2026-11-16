<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute l'année (facultative) d'un article : un repère pour s'y retrouver dans la liste
 * de l'admin. Elle n'est ni affichée sur le site ni utilisée pour ranger les articles.
 * Purement additive : la colonne est vide pour les articles existants, rien ne change sur le site.
 */
final class Version20261116120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional article.annee (year), shown in the admin list as a landmark only';
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
