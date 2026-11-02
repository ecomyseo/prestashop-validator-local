<?php
/**
 * Detector de idioma para la descripción del módulo (pestaña Structure del oficial:
 * «It seems your module has not been developed in English...»).
 *
 * Palabras vacías y palabras típicas de descripciones de módulos por idioma. Ante la duda
 * (ninguna señal) se da por inglés: mejor no inventar un error que el oficial no da.
 */
class VlIdioma
{
    const PALABRAS = array(
        'en' => array('the', 'and', 'of', 'to', 'is', 'for', 'with', 'on', 'your', 'you', 'this', 'that', 'from', 'by', 'as',
            'an', 'are', 'it', 'be', 'or', 'at', 'all', 'into', 'its', 'our', 'can', 'will', 'more', 'new', 'without', 'which',
            'allows', 'allow', 'adds', 'add', 'displays', 'display', 'manage', 'manages', 'replace', 'replaces', 'recover',
            'recovers', 'show', 'shows', 'create', 'creates', 'automatically', 'easily', 'customers', 'store', 'products',
            'orders', 'shipping', 'payment', 'page', 'pages', 'out', 'when', 'their', 'them', 'they', 'how', 'any', 'each'),
        'es' => array('el', 'la', 'los', 'las', 'de', 'del', 'y', 'en', 'que', 'por', 'para', 'con', 'sin', 'una', 'un', 'su',
            'sus', 'al', 'se', 'o', 'es', 'como', 'más', 'mas', 'desde', 'hasta', 'sobre', 'entre', 'cada', 'todo', 'todos',
            'tu', 'tus', 'fichas', 'ficha', 'productos', 'producto', 'tienda', 'pedidos', 'pedido', 'clientes', 'gestiona',
            'permite', 'muestra', 'añade', 'sustituye', 'recupera', 'envío', 'envíos', 'pago', 'carrito', 'automáticamente'),
        'fr' => array('le', 'la', 'les', 'des', 'du', 'de', 'et', 'en', 'un', 'une', 'pour', 'avec', 'sur', 'dans', 'par',
            'est', 'vos', 'votre', 'aux', 'au', 'ou', 'sans', 'qui', 'que', 'produits', 'boutique', 'commandes', 'clients',
            'permet', 'affiche', 'ajoute', 'panier', 'livraison', 'paiement'),
        'it' => array('il', 'lo', 'la', 'gli', 'le', 'di', 'del', 'della', 'dei', 'e', 'in', 'per', 'con', 'su', 'un', 'una',
            'che', 'è', 'i', 'dal', 'al', 'prodotti', 'negozio', 'ordini', 'clienti', 'permette', 'mostra', 'carrello'),
        'de' => array('der', 'die', 'das', 'und', 'in', 'für', 'mit', 'von', 'zu', 'den', 'dem', 'ein', 'eine', 'ist', 'auf',
            'im', 'ihre', 'ihren', 'produkte', 'bestellungen', 'kunden', 'warenkorb', 'versand'),
        'pt' => array('o', 'a', 'os', 'as', 'de', 'do', 'da', 'dos', 'das', 'e', 'em', 'para', 'com', 'sem', 'um', 'uma',
            'por', 'que', 'seu', 'sua', 'produtos', 'loja', 'pedidos', 'clientes', 'permite', 'carrinho'),
        'nl' => array('de', 'het', 'een', 'en', 'van', 'voor', 'met', 'op', 'in', 'uw', 'je', 'producten', 'winkel', 'bestellingen'),
    );

    /** @return array<string,int> puntuación por idioma */
    public static function puntuar(string $texto): array
    {
        $t = mb_strtolower($texto, 'UTF-8');
        preg_match_all('#[\p{L}]+#u', $t, $mm);
        $out = array();
        foreach (self::PALABRAS as $idioma => $lista) {
            $out[$idioma] = 0;
        }
        foreach ($mm[0] as $w) {
            foreach (self::PALABRAS as $idioma => $lista) {
                if (in_array($w, $lista, true)) {
                    ++$out[$idioma];
                }
            }
        }

        return $out;
    }

    public static function esIngles(string $texto): bool
    {
        $p = self::puntuar($texto);
        $en = $p['en'];
        unset($p['en']);
        $otro = max($p);
        if ($en === 0 && $otro === 0) {
            // sin señal: solo se da por no inglés si lleva letras propias de otros idiomas
            return !preg_match('#[ñáéíóúàèìòùâêîôûäëïöüçœß¿¡]#iu', $texto);
        }

        return $en >= $otro;
    }
}
