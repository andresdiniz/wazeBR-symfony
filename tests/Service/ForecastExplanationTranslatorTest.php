<?php

declare(strict_types=1);
namespace App\Tests\Service;

use App\Service\ForecastExplanationTranslator;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ForecastExplanationTranslatorTest extends TestCase
{
    private const CONTEXT = 'Nenhum sinal relevante encontrado nos dados consultados; isso nao confirma ausencia de incidentes. Contexto associado, nao causa comprovada.';

    private function service(string $locale): ForecastExplanationTranslator
    {
        $messages = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/translations/forecast_explanation.' . $locale . '.json'), true, 512, JSON_THROW_ON_ERROR);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function ($key, $params = [], $domain = null, $locale = null) use ($messages): string {
            self::assertSame('forecast_explanation', $domain);
            return strtr($messages[$key] ?? $key, $params);
        });
        return new ForecastExplanationTranslator($translator);
    }

    public function testAllDisplayedExamplesInAllLocales(): void
    {
        foreach (['pt_BR', 'en', 'es'] as $locale) {
            $service = $this->service($locale);
            foreach ([['18','acima'], ['41','acima'], ['33','acima'], ['20','abaixo'], ['22','acima']] as [$seconds, $direction]) {
                $raw = 'Previsao ' . $seconds . ' s ' . $direction . ' do medido. ' . self::CONTEXT;
                $result = $service->translate($raw);
                self::assertNotNull($result);
                self::assertStringContainsString($seconds . ' s', $result);
                self::assertNotSame($raw, $result);
            }
        }
    }

    public function testUnknownContextIsNeverReplacedWithNoSignal(): void
    {
        $raw = 'Previsao 18 s acima do medido. Acidente observado na pista.';
        self::assertSame($raw, $this->service('en')->translate($raw));
        self::assertSame('Texto livre de auditoria.', $this->service('en')->translate('Texto livre de auditoria.'));
    }

    public function testAccentedVariantAndDecimalsPreserveTheirValues(): void
    {
        $context = str_replace(['nao', 'ausencia'], ['não', 'ausência'], self::CONTEXT);
        $result = $this->service('en')->translate('Previsão 18,5 s abaixo do medido. ' . $context);
        self::assertStringContainsString('18,5 s below', (string) $result);
        self::assertStringContainsString('does not confirm the absence of incidents', (string) $result);
        self::assertStringContainsString('not a proven cause', (string) $result);
    }

    public function testMissingCataloguePreservesOriginal(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $service = new ForecastExplanationTranslator($translator);
        $raw = 'Previsao 18 s acima do medido. ' . self::CONTEXT;
        self::assertSame($raw, $service->translate($raw));
    }

    public function testEmptyInputsArePreserved(): void
    {
        $service = $this->service('en');
        self::assertNull($service->translate(null));
        self::assertSame('', $service->translate(''));
        self::assertSame('  ', $service->translate('  '));
    }
}
