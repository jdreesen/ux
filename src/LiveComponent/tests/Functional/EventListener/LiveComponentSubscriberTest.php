<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\UX\LiveComponent\Tests\Functional\EventListener;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\LiveComponentHydrator;
use Symfony\UX\LiveComponent\Tests\Fixture\Component\Component1;
use Symfony\UX\LiveComponent\Tests\Fixture\Component\Component2;
use Symfony\UX\LiveComponent\Tests\Fixture\Entity\Entity1;
use Symfony\UX\TwigComponent\ComponentFactory;
use Zenstruck\Browser\Response\HtmlResponse;
use Zenstruck\Browser\Test\HasBrowser;
use function Zenstruck\Foundry\create;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class LiveComponentSubscriberTest extends KernelTestCase
{
    use Factories;
    use HasBrowser;
    use ResetDatabase;

    public function testCanRenderComponentAsHtmlOrJson(): void
    {
        self::bootKernel();

        /** @var LiveComponentHydrator $hydrator */
        $hydrator = self::$container->get(LiveComponentHydrator::class);

        /** @var ComponentFactory $factory */
        $factory = self::$container->get(ComponentFactory::class);

        /** @var Component1 $component */
        $component = $factory->create(Component1::getComponentName(), [
            'prop1' => $entity = create(Entity1::class)->object(),
            'prop2' => $date = new \DateTime('2021-03-05 9:23'),
            'prop3' => 'value3',
            'prop4' => 'value4',
        ]);

        $dehydrated = $hydrator->dehydrate($component);

        $this->browser()
            ->throwExceptions()
            ->get('/_components/component1?'.http_build_query($dehydrated))
            ->assertSuccessful()
            ->assertHeaderContains('Content-Type', 'html')
            ->assertContains('Prop1: '.$entity->id)
            ->assertContains('Prop2: 2021-03-05 9:23')
            ->assertContains('Prop3: value3')
            ->assertContains('Prop4: (none)')

            ->get('/_components/component1?'.http_build_query($dehydrated), ['headers' => ['Accept' => 'application/vnd.live-component+json']])
            ->assertSuccessful()
            ->assertHeaderEquals('Content-Type', 'application/vnd.live-component+json')
            ->assertJsonMatches('keys(@)', ['html', 'data'])
            ->assertJsonMatches("contains(html, 'Prop1: {$entity->id}')", true)
            ->assertJsonMatches("contains(html, 'Prop2: 2021-03-05 9:23')", true)
            ->assertJsonMatches("contains(html, 'Prop3: value3')", true)
            ->assertJsonMatches("contains(html, 'Prop4: (none)')", true)
            ->assertJsonMatches('keys(data)', ['prop1', 'prop2', 'prop3', '_checksum'])
            ->assertJsonMatches('data.prop1', $entity->id)
            ->assertJsonMatches('data.prop2', $date->format('c'))
            ->assertJsonMatches('data.prop3', 'value3')
        ;
    }

    public function testCanExecuteComponentAction(): void
    {
        self::bootKernel();

        /** @var LiveComponentHydrator $hydrator */
        $hydrator = self::$container->get(LiveComponentHydrator::class);

        /** @var ComponentFactory $factory */
        $factory = self::$container->get(ComponentFactory::class);

        /** @var Component2 $component */
        $component = $factory->create(Component2::getComponentName());

        $dehydrated = $hydrator->dehydrate($component);
        $token = null;

        $this->browser()
            ->throwExceptions()
            ->get('/_components/component2?'.http_build_query($dehydrated))
            ->assertSuccessful()
            ->assertHeaderContains('Content-Type', 'html')
            ->assertContains('Count: 1')
            ->use(function (HtmlResponse $response) use (&$token) {
                // get a valid token to use for actions
                $token = $response->crawler()->filter('div')->first()->attr('data-live-csrf-value');
            })
            ->post('/_components/component2/increase?'.http_build_query($dehydrated), [
                'headers' => ['X-CSRF-TOKEN' => $token],
            ])
            ->assertSuccessful()
            ->assertHeaderContains('Content-Type', 'html')
            ->assertContains('Count: 2')

            ->get('/_components/component2?'.http_build_query($dehydrated), ['headers' => ['Accept' => 'application/vnd.live-component+json']])
            ->assertSuccessful()
            ->assertJsonMatches('data.count', 1)
            ->assertJsonMatches("contains(html, 'Count: 1')", true)
            ->post('/_components/component2/increase?'.http_build_query($dehydrated), [
                'headers' => [
                    'Accept' => 'application/vnd.live-component+json',
                    'X-CSRF-TOKEN' => $token,
                ],
            ])
            ->assertSuccessful()
            ->assertJsonMatches('data.count', 2)
            ->assertJsonMatches("contains(html, 'Count: 2')", true)
        ;
    }

    public function testCannotExecuteComponentActionForGetRequest(): void
    {
        $this->browser()
            ->get('/_components/component2/increase')
            ->assertStatus(405)
        ;
    }

    public function testMissingCsrfTokenForComponentActionFails(): void
    {
        $this->browser()
            ->post('/_components/component2/increase')
            ->assertStatus(400)
        ;
    }

    public function testInvalidCsrfTokenForComponentActionFails(): void
    {
        $this->browser()
            ->post('/_components/component2/increase', [
                'headers' => ['X-CSRF-TOKEN' => 'invalid'],
            ])
            ->assertStatus(400)
        ;
    }

    public function testBeforeReRenderHookOnlyExecutedDuringAjax(): void
    {
        self::bootKernel();

        /** @var LiveComponentHydrator $hydrator */
        $hydrator = self::$container->get(LiveComponentHydrator::class);

        /** @var ComponentFactory $factory */
        $factory = self::$container->get(ComponentFactory::class);

        /** @var Component2 $component */
        $component = $factory->create(Component2::getComponentName());

        $dehydrated = $hydrator->dehydrate($component);

        $this->browser()
            ->visit('/render-template/template1')
            ->assertSuccessful()
            ->assertSee('BeforeReRenderCalled: No')
            ->get('/_components/component2?'.http_build_query($dehydrated))
            ->assertSuccessful()
            ->assertSee('BeforeReRenderCalled: Yes')
        ;
    }

    public function testCanRedirectFromComponentAction(): void
    {
        self::bootKernel();

        /** @var LiveComponentHydrator $hydrator */
        $hydrator = self::$container->get(LiveComponentHydrator::class);

        /** @var ComponentFactory $factory */
        $factory = self::$container->get(ComponentFactory::class);

        /** @var Component2 $component */
        $component = $factory->create(Component2::getComponentName());

        $dehydrated = $hydrator->dehydrate($component);
        $token = null;

        $this->browser()
            ->throwExceptions()
            ->get('/_components/component2?'.http_build_query($dehydrated))
            ->assertSuccessful()
            ->use(function (HtmlResponse $response) use (&$token) {
                // get a valid token to use for actions
                $token = $response->crawler()->filter('div')->first()->attr('data-live-csrf-value');
            })
            ->interceptRedirects()
            ->post('/_components/component2/redirect?'.http_build_query($dehydrated), [
                'headers' => ['X-CSRF-TOKEN' => $token],
            ])
            ->assertRedirectedTo('/')

            ->post('/_components/component2/redirect?'.http_build_query($dehydrated), [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-CSRF-TOKEN' => $token,
                ],
            ])
            ->assertSuccessful()
            ->assertJsonMatches('redirect_url', '/')
        ;
    }
}
