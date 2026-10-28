<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Nach den bekannten Laravel-Lang-Community-Übersetzungen transkribiert,
    | angepasst auf die Du-Form.
    |
    */

    'accepted' => ':Attribute muss akzeptiert werden.',
    'accepted_if' => ':Attribute muss akzeptiert werden, wenn :other :value ist.',
    'active_url' => ':Attribute ist keine gültige Internet-Adresse.',
    'after' => ':Attribute muss ein Datum nach dem :date sein.',
    'after_or_equal' => ':Attribute muss ein Datum nach dem :date oder gleich dem :date sein.',
    'alpha' => ':Attribute darf nur aus Buchstaben bestehen.',
    'alpha_dash' => ':Attribute darf nur aus Buchstaben, Zahlen, Binde- und Unterstrichen bestehen.',
    'alpha_num' => ':Attribute darf nur aus Buchstaben und Zahlen bestehen.',
    'any_of' => ':Attribute ist ungültig.',
    'array' => ':Attribute muss eine Liste sein.',
    'ascii' => ':Attribute darf nur alphanumerische Ein-Byte-Zeichen und Symbole enthalten.',
    'before' => ':Attribute muss ein Datum vor dem :date sein.',
    'before_or_equal' => ':Attribute muss ein Datum vor dem :date oder gleich dem :date sein.',
    'between' => [
        'array' => ':Attribute muss zwischen :min und :max Elemente haben.',
        'file' => ':Attribute muss zwischen :min und :max Kilobytes groß sein.',
        'numeric' => ':Attribute muss zwischen :min und :max liegen.',
        'string' => ':Attribute muss zwischen :min und :max Zeichen lang sein.',
    ],
    'boolean' => ':Attribute muss entweder \'true\' oder \'false\' sein.',
    'can' => ':Attribute enthält einen unautorisierten Wert.',
    'confirmed' => ':Attribute stimmt nicht mit der Bestätigung überein.',
    'contains' => ':Attribute fehlt ein erforderlicher Wert.',
    'current_password' => 'Das Passwort ist falsch.',
    'date' => ':Attribute muss ein gültiges Datum sein.',
    'date_equals' => ':Attribute muss ein Datum gleich dem :date sein.',
    'date_format' => ':Attribute entspricht nicht dem gültigen Format für :format.',
    'decimal' => ':Attribute muss :decimal Dezimalstellen haben.',
    'declined' => ':Attribute muss abgelehnt werden.',
    'declined_if' => ':Attribute muss abgelehnt werden, wenn :other :value ist.',
    'different' => ':Attribute und :other müssen sich unterscheiden.',
    'digits' => ':Attribute muss :digits Stellen haben.',
    'digits_between' => ':Attribute muss zwischen :min und :max Stellen haben.',
    'dimensions' => ':Attribute hat ungültige Bildabmessungen.',
    'distinct' => ':Attribute enthält einen bereits vorhandenen Wert.',
    'doesnt_contain' => ':Attribute darf keinen der folgenden Werte enthalten: :values.',
    'doesnt_end_with' => ':Attribute darf nicht mit einem der folgenden Werte enden: :values.',
    'doesnt_start_with' => ':Attribute darf nicht mit einem der folgenden Werte beginnen: :values.',
    'email' => ':Attribute muss eine gültige E-Mail-Adresse sein.',
    'ends_with' => ':Attribute muss mit einem der folgenden Werte enden: :values.',
    'enum' => 'Der ausgewählte Wert für :attribute ist ungültig.',
    'exists' => 'Der ausgewählte Wert für :attribute ist ungültig.',
    'extensions' => ':Attribute muss eine der folgenden Dateiendungen haben: :values.',
    'file' => ':Attribute muss eine Datei sein.',
    'filled' => ':Attribute muss ausgefüllt sein.',
    'gt' => [
        'array' => ':Attribute muss mehr als :value Elemente haben.',
        'file' => ':Attribute muss größer als :value Kilobytes sein.',
        'numeric' => ':Attribute muss größer als :value sein.',
        'string' => ':Attribute muss länger als :value Zeichen sein.',
    ],
    'gte' => [
        'array' => ':Attribute muss mindestens :value Elemente haben.',
        'file' => ':Attribute muss größer oder gleich :value Kilobytes sein.',
        'numeric' => ':Attribute muss größer oder gleich :value sein.',
        'string' => ':Attribute muss mindestens :value Zeichen lang sein.',
    ],
    'hex_color' => ':Attribute muss eine gültige Hexadezimal-Farbe sein.',
    'image' => ':Attribute muss ein Bild sein.',
    'in' => 'Der ausgewählte Wert für :attribute ist ungültig.',
    'in_array' => ':Attribute muss in :other vorhanden sein.',
    'in_array_keys' => ':Attribute muss mindestens einen der folgenden Schlüssel enthalten: :values.',
    'integer' => ':Attribute muss eine ganze Zahl sein.',
    'ip' => ':Attribute muss eine gültige IP-Adresse sein.',
    'ipv4' => ':Attribute muss eine gültige IPv4-Adresse sein.',
    'ipv6' => ':Attribute muss eine gültige IPv6-Adresse sein.',
    'json' => ':Attribute muss ein gültiger JSON-String sein.',
    'list' => ':Attribute muss eine Liste sein.',
    'lowercase' => ':Attribute muss in Kleinbuchstaben geschrieben sein.',
    'lt' => [
        'array' => ':Attribute muss weniger als :value Elemente haben.',
        'file' => ':Attribute muss kleiner als :value Kilobytes sein.',
        'numeric' => ':Attribute muss kleiner als :value sein.',
        'string' => ':Attribute muss kürzer als :value Zeichen sein.',
    ],
    'lte' => [
        'array' => ':Attribute darf maximal :value Elemente haben.',
        'file' => ':Attribute muss kleiner oder gleich :value Kilobytes sein.',
        'numeric' => ':Attribute muss kleiner oder gleich :value sein.',
        'string' => ':Attribute darf maximal :value Zeichen lang sein.',
    ],
    'mac_address' => ':Attribute muss eine gültige MAC-Adresse sein.',
    'max' => [
        'array' => ':Attribute darf maximal :max Elemente haben.',
        'file' => ':Attribute darf maximal :max Kilobytes groß sein.',
        'numeric' => ':Attribute darf maximal :max sein.',
        'string' => ':Attribute darf maximal :max Zeichen lang sein.',
    ],
    'max_digits' => ':Attribute darf maximal :max Stellen haben.',
    'mimes' => ':Attribute muss eine Datei vom Typ :values sein.',
    'mimetypes' => ':Attribute muss eine Datei vom Typ :values sein.',
    'min' => [
        'array' => ':Attribute muss mindestens :min Elemente haben.',
        'file' => ':Attribute muss mindestens :min Kilobytes groß sein.',
        'numeric' => ':Attribute muss mindestens :min sein.',
        'string' => ':Attribute muss mindestens :min Zeichen lang sein.',
    ],
    'min_digits' => ':Attribute muss mindestens :min Stellen haben.',
    'missing' => ':Attribute darf nicht vorhanden sein.',
    'missing_if' => ':Attribute darf nicht vorhanden sein, wenn :other :value ist.',
    'missing_unless' => ':Attribute darf nicht vorhanden sein, außer :other ist :value.',
    'missing_with' => ':Attribute darf nicht vorhanden sein, wenn :values vorhanden ist.',
    'missing_with_all' => ':Attribute darf nicht vorhanden sein, wenn :values vorhanden sind.',
    'multiple_of' => ':Attribute muss ein Vielfaches von :value sein.',
    'not_in' => 'Der ausgewählte Wert für :attribute ist ungültig.',
    'not_regex' => ':Attribute hat ein ungültiges Format.',
    'numeric' => ':Attribute muss eine Zahl sein.',
    'password' => [
        'letters' => ':Attribute muss mindestens einen Buchstaben enthalten.',
        'mixed' => ':Attribute muss mindestens einen Groß- und einen Kleinbuchstaben enthalten.',
        'numbers' => ':Attribute muss mindestens eine Zahl enthalten.',
        'symbols' => ':Attribute muss mindestens ein Sonderzeichen enthalten.',
        'uncompromised' => ':Attribute wurde in einem Datenleck gefunden. Bitte wähle ein anderes :attribute.',
    ],
    'present' => ':Attribute muss vorhanden sein.',
    'present_if' => ':Attribute muss vorhanden sein, wenn :other :value ist.',
    'present_unless' => ':Attribute muss vorhanden sein, außer :other ist :value.',
    'present_with' => ':Attribute muss vorhanden sein, wenn :values vorhanden ist.',
    'present_with_all' => ':Attribute muss vorhanden sein, wenn :values vorhanden sind.',
    'prohibited' => ':Attribute ist unzulässig.',
    'prohibited_if' => ':Attribute ist unzulässig, wenn :other :value ist.',
    'prohibited_if_accepted' => ':Attribute ist unzulässig, wenn :other akzeptiert wurde.',
    'prohibited_if_declined' => ':Attribute ist unzulässig, wenn :other abgelehnt wurde.',
    'prohibited_unless' => ':Attribute ist unzulässig, außer :other ist :values.',
    'prohibits' => ':Attribute verbietet das Vorhandensein von :other.',
    'regex' => ':Attribute hat ein ungültiges Format.',
    'required' => ':Attribute muss ausgefüllt werden.',
    'required_array_keys' => ':Attribute muss folgende Einträge enthalten: :values.',
    'required_if' => ':Attribute muss ausgefüllt werden, wenn :other :value ist.',
    'required_if_accepted' => ':Attribute muss ausgefüllt werden, wenn :other akzeptiert wurde.',
    'required_if_declined' => ':Attribute muss ausgefüllt werden, wenn :other abgelehnt wurde.',
    'required_unless' => ':Attribute muss ausgefüllt werden, außer :other ist :values.',
    'required_with' => ':Attribute muss ausgefüllt werden, wenn :values vorhanden ist.',
    'required_with_all' => ':Attribute muss ausgefüllt werden, wenn :values vorhanden sind.',
    'required_without' => ':Attribute muss ausgefüllt werden, wenn :values nicht vorhanden ist.',
    'required_without_all' => ':Attribute muss ausgefüllt werden, wenn keiner der Werte :values vorhanden ist.',
    'same' => ':Attribute und :other müssen übereinstimmen.',
    'size' => [
        'array' => ':Attribute muss genau :size Elemente haben.',
        'file' => ':Attribute muss :size Kilobytes groß sein.',
        'numeric' => ':Attribute muss gleich :size sein.',
        'string' => ':Attribute muss :size Zeichen lang sein.',
    ],
    'starts_with' => ':Attribute muss mit einem der folgenden Werte beginnen: :values.',
    'string' => ':Attribute muss ein Text sein.',
    'timezone' => ':Attribute muss eine gültige Zeitzone sein.',
    'ulid' => ':Attribute muss eine gültige ULID sein.',
    'unique' => ':Attribute ist bereits vergeben.',
    'uploaded' => ':Attribute konnte nicht hochgeladen werden.',
    'uppercase' => ':Attribute muss in Großbuchstaben geschrieben sein.',
    'url' => ':Attribute muss eine gültige URL sein.',
    'uuid' => ':Attribute muss eine gültige UUID sein.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    */

    /*
     * Der Schlüssel ist der Attributname, wie ihn die Regel trägt — bei
     * Livewire-Komponenten also der camelCase-Property-Name, nicht die
     * snake_case-Spalte dahinter.
     */
    'attributes' => [
        'current_password' => 'Aktuelles Passwort',
        'email' => 'E-Mail-Adresse',
        'inactivityThresholdDays' => 'Inaktivitäts-Schwelle (Tage)',
        'name' => 'Name',
        'password' => 'Passwort',
        'password_confirmation' => 'Passwort-Bestätigung',
    ],

];
