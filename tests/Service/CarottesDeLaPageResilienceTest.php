<?php

namespace App\Tests\Service;

use App\Repository\CarotteRepository;
use App\Service\CarottesDeLaPage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Vich\UploaderBundle\Templating\Helper\UploaderHelper;

/**
 * Les carottes cachées ne sont qu'un clin d'œil : si elles ne peuvent pas être préparées, la page
 * s'affiche quand même (sans carotte), et l'erreur est écrite dans le journal. Sans cela, une seule
 * carotte mal réglée rendrait la page de son article introuvable (erreur 500) pour tout le monde.
 */
class CarottesDeLaPageResilienceTest extends TestCase
{
    public function testAFailureWhileReadingTheCarrotsGivesNoCarrotAndLogsIt(): void
    {
        $carottes = $this->createMock(CarotteRepository::class);
        $carottes->method('findPourPage')->willThrowException(new \RuntimeException('table carotte illisible'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains('carottes'), self::callback(
                static fn (array $contexte): bool => ($contexte['exception'] ?? null) instanceof \RuntimeException,
            ));

        self::assertSame([], $this->service($carottes, $logger)->lister());
    }

    public function testNoRequestMeansNoCarrotAndNoError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $service = new CarottesDeLaPage(
            new RequestStack(),
            $this->createMock(CarotteRepository::class),
            $this->uploader(),
            $this->createMock(Security::class),
            $logger,
        );

        self::assertSame([], $service->lister());
    }

    private function service(CarotteRepository $carottes, LoggerInterface $logger): CarottesDeLaPage
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/article/33/flux-libre-2019', 'GET', [], [], [], []));

        return new CarottesDeLaPage($requests, $carottes, $this->uploader(), $this->createMock(Security::class), $logger);
    }

    // UploaderHelper est « final » (on ne peut pas le simuler) et ce test ne s'en sert pas : une coquille vide suffit
    private function uploader(): UploaderHelper
    {
        return (new \ReflectionClass(UploaderHelper::class))->newInstanceWithoutConstructor();
    }
}
