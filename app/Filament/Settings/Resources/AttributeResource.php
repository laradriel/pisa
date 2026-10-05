<?php

namespace App\Filament\Settings\Resources;

use App\Enums\AttributeFormatEnum;
use App\Enums\AttributeTypeEnum;
use App\Filament\Settings\Resources\AttributeResource\Pages;
use App\Filament\Settings\Resources\AttributeResource\RelationManagers\OptionsRelationManager;
use App\Models\Attribute;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AttributeResource extends Resource
{
    protected static ?string $model = Attribute::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(144),
                Forms\Components\TextInput::make('code')
                    ->unique(table: Attribute::class, ignoreRecord: true)
                    ->regex('/^[a-z][a-z0-9-]*$/')
                    ->required()
                    ->maxLength(144),
                Forms\Components\Textarea::make('description')
                    ->maxLength(255)
                    ->columnSpan(2),
                Forms\Components\Select::make('type')
                    ->searchable()
                    ->options(AttributeTypeEnum::options())
                    ->required(),
                Forms\Components\Select::make('format')
                    ->searchable()
                    ->options(AttributeFormatEnum::options())
                    ->required(),
                Forms\Components\Toggle::make('is_distributable')
                    ->label(__('Value per Channel'))
                    ->default(false)
                    ->inline(false)
                    ->required(),
                Forms\Components\Toggle::make('is_territorial')
                    ->label(__('Value per Territory'))
                    ->default(false)
                    ->inline(false)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('description')
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->searchable(),
                Tables\Columns\TextColumn::make('format')
                    ->searchable(),
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
            OptionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAttributes::route('/'),
            'create' => Pages\CreateAttribute::route('/create'),
            'view' => Pages\ViewAttribute::route('/{record}'),
            'edit' => Pages\EditAttribute::route('/{record}/edit'),
        ];
    }
}
