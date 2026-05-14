<?php
namespace App\Support;

class VehiculoData
{
    // Class implementation
    public static function getBrands(): array
    {
        return [
            'Volvo', 'Scania', 'Mercedes-Benz', 'Freightliner', 'Kenworth',
            'Peterbilt', 'International', 'Isuzu', 'Hino', 'Mitsubishi Fuso',
            'MAN', 'DAF', 'Iveco', 'Mack', 'Hyundai', 'Dongfeng', 'Foton',
        ];
    }

    public static function getModels(): array
    {
        return [
            'FH16', 'R-Series', 'Actros', 'Cascadia', 'W900', 'Model 389',
            'LoneStar', 'NPR', '500 Series', 'Canter', 'TGX', 'XF',
            'Eurocargo', 'Anthem', 'Mighty', 'Auman', 'VNL',
        ];
    }

    public static function getColors(): array
    {
        return ['Blanco', 'Negro', 'Gris', 'Plateado', 'Rojo', 'Azul', 'Verde', 'Amarillo', 'Marrón'];
    }
}
