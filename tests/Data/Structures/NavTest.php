<?php

namespace Tests\Data\Structures;

use Facades\Statamic\Stache\Repositories\NavTreeRepository;
use Illuminate\Support\Collection as LaravelCollection;
use Statamic\Contracts\Entries\Collection as StatamicCollection;
use Statamic\Facades;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Structures\Nav;
use Statamic\Structures\NavTree;
use Tests\FakesRoles;
use Tests\PreventSavingStacheItemsToDisk;

class NavTest extends StructureTestCase
{
    use FakesRoles;
    use PreventSavingStacheItemsToDisk;

    public function structure($handle = null)
    {
        return (new Nav)->handle($handle);
    }

    /** @test */
    public function it_gets_and_sets_the_handle()
    {
        $structure = $this->structure();
        $this->assertNull($structure->handle());

        $return = $structure->handle('test');

        $this->assertEquals('test', $structure->handle());
        $this->assertEquals($structure, $return);
    }

    /** @test */
    public function it_makes_a_tree()
    {
        $structure = $this->structure()->handle('test');
        Facades\Nav::shouldReceive('findByHandle')->with('test')->andReturn($structure);
        $tree = $structure->makeTree('fr', [
            ['url' => '/test'],
        ]);
        $this->assertEquals('fr', $tree->locale());
        $this->assertEquals('test', $tree->handle());
        $this->assertEquals([
            ['url' => '/test'],
        ], $tree->tree());
    }

    /** @test */
    public function trees_exist_if_they_exist_as_files()
    {
        Site::setConfig(['sites' => [
            'en' => ['url' => '/', 'locale' => 'en'],
            'fr' => ['url' => '/fr/', 'locale' => 'fr'],
            'de' => ['url' => '/de/', 'locale' => 'de'],
        ]]);

        // ...unlike collection structure trees, that exist if they're defined in the collection
        // regardless of whether a file exists.

        $structure = $this->structure('test');

        NavTreeRepository::shouldReceive('find')->with('test', 'en')->andReturn($enTree = $structure->makeTree('en'));
        NavTreeRepository::shouldReceive('find')->with('test', 'fr')->andReturn($frTree = $structure->makeTree('fr'));
        NavTreeRepository::shouldReceive('find')->with('test', 'de')->andReturnNull();

        $trees = $structure->trees();
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $trees);
        $this->assertCount(2, $trees);
        $this->assertEveryItemIsInstanceOf(NavTree::class, $trees);
        $this->assertTrue($structure->existsIn('en'));
        $this->assertTrue($structure->existsIn('fr'));
        $this->assertFalse($structure->existsIn('de'));
        $this->assertSame($enTree, $structure->in('en'));
        $this->assertSame($frTree, $structure->in('fr'));
        $this->assertNull($structure->in('de'));
    }

    /** @test */
    public function it_gets_and_sets_the_title()
    {
        $structure = $this->structure('test');

        // No title set falls back to uppercased version of the handle
        $this->assertEquals('Test', $structure->title());

        $return = $structure->title('Explicitly set title');

        $this->assertEquals('Explicitly set title', $structure->title());
        $this->assertEquals($structure, $return);
    }

    /** @test */
    public function it_saves_the_nav_through_the_api()
    {
        $nav = $this->structure();

        Facades\Nav::shouldReceive('save')->with($nav)->once();

        $this->assertTrue($nav->save());
    }

    /** @test */
    public function it_deletes_through_the_api()
    {
        $nav = $this->structure();

        Facades\Nav::shouldReceive('delete')->with($nav)->once();

        $this->assertTrue($nav->delete());
    }

    /** @test */
    public function collections_can_be_get_and_set()
    {
        $nav = $this->structure();
        $collectionOne = tap(Facades\Collection::make('one'))->save();
        $collectionTwo = tap(Facades\Collection::make('two'))->save();

        $collections = $nav->collections();
        $this->assertInstanceOf(LaravelCollection::class, $collections);
        $this->assertCount(0, $collections);

        $return = $nav->collections(['one', 'two']);

        $this->assertSame($nav, $return);
        $collections = $nav->collections();
        $this->assertInstanceOf(LaravelCollection::class, $collections);
        $this->assertEveryItemIsInstanceOf(StatamicCollection::class, $collections);
        $this->assertCount(2, $collections);
        $this->assertEquals([$collectionOne, $collectionTwo], $collections->all());
    }

    /** @test */
    public function it_has_cp_urls()
    {
        $nav = $this->structure('test');

        $this->assertEquals('http://localhost/cp/navigation/test', $nav->showUrl());
        $this->assertEquals('http://localhost/cp/navigation/test?foo=bar', $nav->showUrl(['foo' => 'bar']));
        $this->assertEquals('http://localhost/cp/navigation/test/edit', $nav->editUrl());
        $this->assertEquals('http://localhost/cp/navigation/test', $nav->deleteUrl());
    }

    /** @test */
    public function it_has_no_route()
    {
        $this->assertNull($this->structure('test')->route('en'));
    }

    /** @test */
    public function it_gets_available_sites_from_trees()
    {
        Site::setConfig([
            'default' => 'en',
            'sites' => [
                'en' => ['name' => 'English', 'locale' => 'en_US', 'url' => 'http://test.com/'],
                'fr' => ['name' => 'French', 'locale' => 'fr_FR', 'url' => 'http://fr.test.com/'],
                'de' => ['name' => 'German', 'locale' => 'de_DE', 'url' => 'http://test.com/de/'],
            ],
        ]);

        $nav = tap(Facades\Nav::make()->handle('test'))->save();
        $nav->makeTree('en')->save();
        $nav->makeTree('fr')->save();

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $nav->sites());
        $this->assertEquals(['en', 'fr'], $nav->sites()->all());
    }

    /** @test */
    public function it_cannot_view_navs_from_sites_that_the_user_is_not_authorized_to_see()
    {
        Site::setConfig([
            'default' => 'en',
            'sites' => [
                'en' => ['name' => 'English', 'locale' => 'en_US', 'url' => 'http://test.com/'],
                'fr' => ['name' => 'French', 'locale' => 'fr_FR', 'url' => 'http://fr.test.com/'],
                'de' => ['name' => 'German', 'locale' => 'de_DE', 'url' => 'http://test.com/de/'],
            ],
        ]);

        $nav1 = tap(Facades\Nav::make()->handle('has_some_french'))->save();
        $nav1->makeTree('en')->save();
        $nav1->makeTree('fr')->save();
        $nav1->makeTree('de')->save();

        $nav2 = tap(Facades\Nav::make()->handle('has_no_french'))->save();
        $nav2->makeTree('en')->save();
        $nav2->makeTree('de')->save();

        $nav3 = tap(Facades\Nav::make()->handle('has_only_french'))->save();
        $nav3->makeTree('fr')->save();

        $this->setTestRoles(['test' => [
            'access cp',
            'view has_some_french nav',
            'view has_no_french nav',
            'view has_only_french nav',
            'access en site',
            // 'access fr site', // Give them access to all data, but not all sites
            'access de site',
        ]]);

        $user = tap(User::make()->assignRole('test'))->save();
        $this->assertTrue($user->can('view', $nav1));
        $this->assertTrue($user->can('view', $nav2));
        $this->assertFalse($user->can('view', $nav3));
    }
}
