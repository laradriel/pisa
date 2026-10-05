<?php

namespace Tests\Feature;

use App\Filament\Pim\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Settings\Resources\AttributeResource\Pages\CreateAttribute;
use App\Models\Family;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CodeAndSkuValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_sku_must_be_uppercase_alphanumeric_with_dashes(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('pim'));
        $this->actingAs(User::factory()->create(['email' => 'user@pim.com']));
        $family = Family::factory()->create();

        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'Shirt', 'sku' => 'abc', 'family_id' => $family->id])
            ->call('create')
            ->assertHasFormErrors(['sku' => 'regex']);

        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'Shirt', 'sku' => 'ABC-1', 'family_id' => $family->id])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_attribute_code_must_be_lowercase_slug(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('settings'));
        $this->actingAs(User::factory()->create(['email' => 'user@pim.com']));

        $data = ['name' => 'Color', 'type' => 'short_text', 'format' => 'text'];

        Livewire::test(CreateAttribute::class)
            ->fillForm($data + ['code' => 'Color'])
            ->call('create')
            ->assertHasFormErrors(['code' => 'regex']);

        Livewire::test(CreateAttribute::class)
            ->fillForm($data + ['code' => 'color-1'])
            ->call('create')
            ->assertHasNoFormErrors();
    }
}
