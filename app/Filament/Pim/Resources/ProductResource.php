<?php

namespace App\Filament\Pim\Resources;

use App\Filament\Pim\Resources\ProductResource\Pages;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-bookmark';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('sku')
                    ->label('SKU')
                    ->required()
                    ->regex('/^[A-Z0-9-]+$/'),
                Forms\Components\Select::make('family_id')
                    ->label('Family')
                    ->searchable(['name'])
                    ->preload()
                    ->relationship('family', 'name'),
            ]);
    }

    public static function table(Table $table): Table
    {

        //$columns = function (?Product $record = null) {
        //    return $record?->attributeValues->collect()->map(function (ProductAttributeValue $attributeValue) {
        //        return $attributeValue->attribute->toFilamentField();
        //    })->toArray();
        //};

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('family.name'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                //...(fn (Product $record) => $columns($record))($record ?? null)
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /**
     * @return Builder<Product>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'view' => Pages\ViewProduct::route('/{record}'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
