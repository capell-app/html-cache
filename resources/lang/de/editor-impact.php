<?php

declare(strict_types=1);

return [
    'html_label' => 'HTML-Cache',
    'html_instant' => 'Eine Änderung dieses Datensatzes macht diese URLs sofort ungültig. Die Anzahlen berücksichtigen nur Websites, die Sie ansehen dürfen; es werden bis zu 10 URLs angezeigt.',
    'html_scheduled' => 'Eine Änderung dieses Datensatzes markiert diese erfassten URLs für die geplante Aktualisierung als veraltet. Die Anzahlen berücksichtigen nur Websites, die Sie ansehen dürfen; es werden bis zu 10 URLs angezeigt.',
    'html_layout' => 'Das Speichern dieses Layouts macht den Cache der betroffenen Website sofort ungültig, auch im geplanten Modus. Die Anzahlen berücksichtigen nur Websites, die Sie ansehen dürfen; es werden bis zu 10 URLs angezeigt.',
    'translation_label' => 'Cache für übersetzte Inhalte, falls bearbeitet',
    'translation_description' => 'Wenn sich bestehende Seitenübersetzungen ändern, können zusätzliche erfasste URLs betroffen sein. Dieser Umfang gilt nur bedingt und schließt URLs aus, die oben bereits gezählt wurden; unveränderte Übersetzungen machen diese Einträge nicht ungültig.',
    'estimate_unavailable' => 'Zeitangabe nicht verfügbar: Für die betroffenen Websites liegt kein verwertbarer Verlauf abgeschlossener Generierungen vor.',
    'estimate_basis' => 'Ungefähre Regenerierungsdauer: :affected betroffene URLs × :seconds Sekunden aus einer abgeschlossenen Generierung ÷ :total aktuell erfasste URLs dieser Websites. Die aktuelle URL-Anzahl dient als Näherung für die frühere Arbeitslast. Wartezeiten in der Warteschlange und manuelle Suchaufgaben werden nicht vorhergesagt.',
];
