<?php

namespace App\DataFixtures;

use App\Entity\Admin;
use App\Entity\Article;
use App\Entity\Categorie;
use App\Entity\Media;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
  private UserPasswordHasherInterface $passwordHasher;

  // Injection du service pour hasher le mot de passe de l'admin
  public function __construct(UserPasswordHasherInterface $passwordHasher)
  {
    $this->passwordHasher = $passwordHasher;
  }

  public function load(ObjectManager $manager): void
  {
    // Initialisation de Faker en français
    $faker = Factory::create('fr_FR');

    // ==========================================
    // 1. Création de l'Admin
    // ==========================================
    $admin = new Admin();
    $admin->setUsername('caroline_admin');
    $admin->setRoles(['ROLE_ADMIN']);
    // Mot de passe : "password123" (à changer en prod !)
    $hashedPassword = $this->passwordHasher->hashPassword($admin, 'password123');
    $admin->setPassword($hashedPassword);

    $manager->persist($admin);

    // ==========================================
    // 2. Création des Catégories
    // ==========================================
    $categories = [];
    for ($i = 0; $i < 5; $i++) {
      $categorie = new Categorie();
      $categorie->setTitre($faker->sentence(3));
      $categorie->setDescription($faker->paragraph());

      // Gestion de datetime_immutable
      $dateCreation = \DateTimeImmutable::createFromMutable($faker->dateTimeBetween('-1 year', '-6 months'));
      $categorie->setCreeLe($dateCreation);

      $manager->persist($categorie);
      $categories[] = $categorie; // On les stocke pour les lier aux articles ensuite
    }

    // ==========================================
    // 3. Création des Articles et de leurs Médias
    // ==========================================
    for ($i = 0; $i < 20; $i++) {
      $article = new Article();
      $article->setTitre($faker->sentence());
      $article->setDescription($faker->paragraphs(3, true));
      $article->setImageName('default_article_' . $i . '.jpg');
      $article->setPosition($i + 1);

      $dateCreationArticle = \DateTimeImmutable::createFromMutable($faker->dateTimeBetween('-5 months', 'now'));
      $article->setCreeLe($dateCreationArticle);

      // Assignation d'une catégorie aléatoire
      $article->setCategorie($faker->randomElement($categories));

      $manager->persist($article);

      // ==========================================
      // 4. Création des Médias liés à cet Article
      // ==========================================
      // On génère entre 1 et 3 médias par article
      $nbMedias = mt_rand(1, 3);
      for ($j = 0; $j < $nbMedias; $j++) {
        $media = new Media();
        $media->setImageName('media_' . $i . '_' . $j . '.jpg');
        $media->setLegende($faker->sentence(4));
        $media->setCreeLe($faker->dateTimeBetween($dateCreationArticle->format('Y-m-d H:i:s'), 'now'));

        // LIAISON AVEC L'ARTICLE
        // À adapter selon si votre entité utilise setArticle() [ManyToOne] ou addMedium() [ManyToMany]
        if (method_exists($media, 'setArticle')) {
          $media->setArticle($article);
        }
        if (method_exists($article, 'addMedium')) {
          $article->addMedium($media);
        }
        // (Si votre entité s'appelle autrement, par ex addMedia(), ajustez ici)

        $manager->persist($media);
      }
    }

    // ==========================================
    // 5. Exécution des requêtes SQL
    // ==========================================
    $manager->flush();
  }
}
