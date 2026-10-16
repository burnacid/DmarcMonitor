<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class SearchableSelectComponentTest extends TestCase
{
    public function test_it_renders_options_in_order_with_a_selectable_placeholder_first(): void
    {
        $html = Blade::render('<x-searchable-select wire:model.live="domainId" :options="$options" placeholder="All domains" />', [
            'options' => [3 => 'b.test', 1 => 'a.test'],
        ]);

        $this->assertStringContainsString('x-modelable="value"', $html);
        $this->assertStringContainsString('wire:model.live="domainId"', $html);
        $this->assertSame([
            ['value' => '', 'label' => 'All domains'],
            ['value' => '3', 'label' => 'b.test'],
            ['value' => '1', 'label' => 'a.test'],
        ], $this->renderedOptions($html));
    }

    public function test_a_non_selectable_placeholder_is_left_out_of_the_options(): void
    {
        $html = Blade::render('<x-searchable-select wire:model="organisationId" :options="[5 => \'Acme\']" placeholder="Select organisation" :placeholder-selectable="false" />');

        $this->assertSame([['value' => '5', 'label' => 'Acme']], $this->renderedOptions($html));
        $this->assertStringContainsString('Select organisation', $html);
    }

    public function test_the_wire_key_changes_when_the_options_change(): void
    {
        $template = '<x-searchable-select wire:model="domainId" wire:key="domain-filter" :options="$options" />';

        $first = Blade::render($template, ['options' => [1 => 'a.test']]);
        $second = Blade::render($template, ['options' => [2 => 'b.test']]);

        $this->assertMatchesRegularExpression('/wire:key="domain-filter-[0-9a-f]{32}"/', $first);
        $this->assertNotSame($this->wireKey($first), $this->wireKey($second));
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function renderedOptions(string $html): array
    {
        preg_match("/options: JSON\.parse\('(.*?)'\)/", $html, $matches);

        return json_decode(json_decode('"'.$matches[1].'"'), true);
    }

    private function wireKey(string $html): string
    {
        preg_match('/wire:key="([^"]+)"/', $html, $matches);

        return $matches[1];
    }
}
