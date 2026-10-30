<?php

namespace App\WhatsApp\Waha;

/**
 * WAHA identifica un mensaje con «true_51987654321@c.us_3EB0ABC…» en unos eventos y con otras formas en otros.
 * El tramo estable y único es el último segmento tras «_»: así el id devuelto por sendText y el de message.ack
 * coinciden aunque el prefijo difiera.
 */
final class WahaMessageId
{
    public static function normalize(string $id): string
    {
        $id = trim($id);
        $pos = strrpos($id, '_');

        return $pos === false ? $id : substr($id, $pos + 1);
    }
}
