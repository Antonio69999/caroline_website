<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Crée la table carotte : les carottes cachées que Caroline place sur les pages du site depuis
 * l'admin (où elle se cache, l'image qu'elle révèle, sa taille, sa transparence, sa place…).
 * Purement additive : aucune table existante n'est modifiée, et tant que la table est vide le
 * site se comporte exactement comme avant.
 */
final class Version20261119120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create carotte table (hidden carrots placed on the site pages from the admin)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE carotte (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(120) NOT NULL, actif TINYINT(1) DEFAULT 1 NOT NULL, cible VARCHAR(40) DEFAULT 'partout' NOT NULL, image_name VARCHAR(255) DEFAULT NULL, description VARCHAR(255) DEFAULT NULL, mode_reglage VARCHAR(10) DEFAULT 'choisi' NOT NULL, carotte_taille VARCHAR(12) DEFAULT 'normale' NOT NULL, carotte_opacite SMALLINT DEFAULT 100 NOT NULL, carotte_place VARCHAR(20) DEFAULT 'bas-droite' NOT NULL, delai SMALLINT DEFAULT 0 NOT NULL, image_taille VARCHAR(12) DEFAULT 'moyenne' NOT NULL, image_place VARCHAR(20) DEFAULT 'milieu-centre' NOT NULL, cree_le DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', modifie_le DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE carotte');
    }
}
