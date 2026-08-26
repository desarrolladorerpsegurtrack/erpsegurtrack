<?php
namespace App\Support;

class VehiculoData
{
    // Class implementation
    public static function getBrands(): array
    {
        return [
            'VOLVO', 'SCANGIA', 'MERCEDES-BENZ', 'FREIGHTLINER', 'KENWORTH',
            'PETERBILT', 'INTERNATIONAL', 'ISUZU', 'HINO', 'MITSUBISHI',
            'MAN', 'DAF', 'IVECO', 'MACK', 'HYUNDAI', 'DONGFENG', 'FOTON', 
            'UD TRUCKS', 'TATA', 'JAC', 'SINOTRUK', 'AUMAN', 'CAMC', 
        ];
    }

    public static function getModels(): array
    {
        return [
            'FH16', 'R-SERIES', 'ACTROS', 'CASDIA', 'W900', 'MODEL 389',
            'LONESTAR', 'NPR', '500 SERIES', 'CANTER', 'TGX', 'XF',
            'EUROCARGO', 'ANTHEM', 'MIGHTY', 'AUMAN', 'VNL', 'FUSO',
        ];
    }

    public static function getColors(): array
    {
        return ['BLANCO', 'NEGRO', 'GRIS', 'PLATEADO', 'ROJO', 'AZUL', 'VERDE', 'AMARILLO', 'MARRÓN'];
    }
}
