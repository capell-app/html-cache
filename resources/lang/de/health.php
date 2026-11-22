<?php

declare(strict_types=1);

return [
    'exhausted' => [
        'label' => 'HTML-Cache: fehlgeschlagene Aktualisierungen innerhalb des Limits',
        'passed' => 'Bei keiner veralteten HTML-Cache-URL sind die Aktualisierungsversuche ausgeschöpft.',
        'failed' => 'Bei :count veralteten HTML-Cache-URLs sind die Aktualisierungsversuche ausgeschöpft; das vorherige HTML bleibt verfügbar.',
        'remediation' => 'Prüfen Sie die ausgeschöpften Zeilen und deren last_error in stale_cached_urls, beheben Sie den Fehler am Ursprungsserver und markieren Sie diese URLs anschließend erneut als veraltet oder löschen Sie sie ausdrücklich.',
    ],
    'edge_purge_throttle' => [
        'label' => 'HTML-Cache: Taktung der Edge-Bereinigung',
        'inactive' => 'Eingereihte Edge-Bereinigungen werden direkt gesendet: Der konfigurierte Bereinigungstreiber hat keine ratenbegrenzte Zone oder die Taktung ist deaktiviert.',
        'passed' => 'Die Edge-Zone :zone bereinigt mit einer ermittelten Rate von :rate Anfragen pro Sekunde (Obergrenze :ceiling); :pending Bereinigungen ausstehend, die älteste seit :age Sekunden; letzter Anbieterstatus :status.',
        'paused' => 'Die Edge-Zone :zone wurde vom Anbieter ratenbegrenzt (Status :status) und wird in :waiting Sekunden mit einer ermittelten Rate von :rate Anfragen pro Sekunde fortgesetzt (Obergrenze :ceiling); :pending Bereinigungen ausstehend, die älteste seit :age Sekunden.',
        'backlogged' => 'Die Edge-Zone :zone hat :pending ausstehende Bereinigungen, die älteste seit :age Sekunden, bei einer ermittelten Rate von :rate Anfragen pro Sekunde (Obergrenze :ceiling); letzter Anbieterstatus :status.',
        'backlogged_remediation' => 'Prüfen Sie, ob ein Queue-Worker läuft, und lesen Sie die Protokolleinträge zur Edge-Bereinigung für den Anbieterstatus. Aktivieren Sie escalate_to_full_purge, damit ein überfälliger Rückstand durch eine einzige Bereinigung der gesamten Zone ersetzt wird, oder bereinigen Sie die Zone manuell.',
        'no_locks' => 'Der Cache-Speicher für die Taktung der Edge-Bereinigung unterstützt keine atomaren Sperren. Eingereihte Bereinigungen werden daher direkt gesendet, ohne Zusammenfassung oder Ratenbegrenzung.',
        'dropped' => 'Die Edge-Zone :zone hat :count Bereinigungen aufgegeben, die der Anbieter als fehlerhafte Anfragen abgelehnt hat (letzter Status :status, zuletzt um :when). Der Edge liefert daher möglicherweise noch veraltete Kopien von: :sample.',
        'dropped_remediation' => 'Lesen Sie die Fehlerprotokolleinträge zur Edge-Bereinigung, um die abgelehnten URLs oder Tags zu finden, und korrigieren Sie diese. Die Warnung verschwindet, sobald diese URLs oder Tags erfolgreich bereinigt wurden oder nach einer erfolgreichen Bereinigung der gesamten Zone, zum Beispiel durch Leeren des gesamten HTML-Caches mit capell:html-cache:clear. Eine Bereinigung außerhalb von Capell, etwa im Dashboard des Anbieters, wird nicht erkannt; die Warnung läuft dann :lifetime nach der letzten verworfenen Bereinigung ab.',
        'unavailable' => 'Der Taktungsstatus der Edge-Bereinigung konnte nicht aus seinem Cache-Speicher gelesen werden, daher sind die ermittelte Rate und die ausstehenden Bereinigungen unbekannt.',
        'no_locks_remediation' => 'Setzen Sie capell-html-cache.purge.throttle.store auf einen Cache-Speicher, den alle Queue-Worker gemeinsam nutzen und der atomare Sperren unterstützt, zum Beispiel redis.',
    ],
    'disk' => [
        'failed' => 'Auf den Datenträger page_cache konnte nicht geschrieben werden; zwischengespeichertes HTML kann nicht gespeichert werden.',
        'label' => 'HTML-Cache: Cache-Speicher beschreibbar',
        'passed' => 'Der Datenträger page_cache ist konfiguriert und für statische HTML-Ausgabe beschreibbar.',
        'remediation' => 'Stellen Sie sicher, dass der Dateisystem-Datenträger page_cache konfiguriert ist und sein Stammverzeichnis für den Webserver beschreibbar ist.',
    ],
    'middleware' => [
        'failed' => 'Der Middleware-Alias frontend.cache oder der Eintrag im Frontend-Routenstack ist nicht mit der HTML-Cache-Middleware verknüpft; öffentliche Seiten werden nicht zwischengespeichert.',
        'label' => 'HTML-Cache: Zwischenspeicherung öffentlicher Seiten registriert',
        'passed' => 'Der Middleware-Alias frontend.cache verweist auf die HTML-Cache-Middleware und ist im Frontend-Routenstack vorhanden.',
        'remediation' => 'Stellen Sie sicher, dass HtmlCacheServiceProvider den Middleware-Alias frontend.cache registriert und ihn in die Middleware-Registry der Frontend-Routen einfügt.',
    ],
    'local_path' => [
        'failed' => 'Der Datenträger page_cache stellt keine lokalen Dateisystempfade bereit, die für atomares Schreiben von HTML erforderlich sind.',
        'label' => 'HTML-Cache: Cache-Speicher unterstützt sicheres Schreiben von Dateien',
        'passed' => 'Der Datenträger page_cache stellt lokale Dateisystempfade für atomares Schreiben von HTML bereit.',
        'remediation' => 'Verwenden Sie einen lokalen Datenträger oder einen gemeinsam genutzten POSIX-Dateisystem-Datenträger. Object-Storage-Treiber werden vom HTML-Datei-Cache nicht unterstützt.',
    ],
    'multi_node_purge' => [
        'failed' => 'Der HTML-Cache ist für :nodes Webknoten konfiguriert, page_cache ist jedoch knotenlokal und der Bereinigungstreiber :driver kann die übrigen Knoten nicht ungültig machen.',
        'label' => 'HTML-Cache: Cache-Leerung für jeden Server konfiguriert',
        'single_node' => 'Der HTML-Cache ist für einen Webknoten konfiguriert, daher erreicht die lokale Invalidierung den gesamten Ursprungs-Cache.',
        'non_local_disk' => 'Der HTML-Cache ist für :nodes Webknoten konfiguriert und der Datenträger page_cache verwendet den Treiber :driver. Bestätigen Sie die separate Diagnose zum lokalen Pfad, bevor Sie zwischengespeichertes HTML ausliefern.',
        'shared_disk' => 'Der HTML-Cache ist für :nodes Webknoten mit einem bestätigten gemeinsam genutzten page_cache-Dateisystem konfiguriert.',
        'edge_driver_node_local' => 'Der HTML-Cache ist für :nodes Webknoten mit dem Edge-Bereinigungstreiber :driver konfiguriert, page_cache ist jedoch knotenlokal: Die Bereinigung leert den Edge, während die anderen Knoten ihre eigenen Kopien behalten, sodass der nächste Edge-Miss zurückgezogenes HTML ausliefern kann.',
        'remediation' => 'Verwenden Sie einen Webknoten oder binden Sie ein gemeinsames POSIX-Verzeichnis page_cache auf jedem Web- und Queue-Knoten ein und setzen Sie CAPELL_HTML_CACHE_SHARED_PAGE_CACHE=true. Ein HTTP- oder Cloudflare-Bereinigungstreiber leert zusätzlich den Edge; er ersetzt diesen Speicher nicht. Knotenlokales HTML-Caching über mehrere Knoten wird nicht unterstützt.',
    ],
    'stale_command' => [
        'failed' => 'Die geplante Invalidierung ist aktiv, aber der Befehl :command ist nicht registriert.',
        'label' => 'HTML-Cache: Aktualisierung veralteter Seiten geplant',
        'not_required' => 'Der Invalidierungsmodus ist sofort; eine geplante Neugenerierung veralteter Seiten ist nicht erforderlich.',
        'passed' => 'Die geplante Invalidierung ist aktiv und der Befehl :command ist registriert.',
        'remediation' => 'Stellen Sie sicher, dass HtmlCacheServiceProvider den Befehl :command registriert, solange der Invalidierungsmodus geplant ist.',
    ],
    'tables' => [
        'failed' => 'Fehlende Tabellen: :tables.',
        'label' => 'HTML-Cache: Datenbanktabellen vorhanden',
        'passed' => 'Der Abhängigkeitsindex des HTML-Caches, die Warteschlange veralteter Seiten und die Tabellen der statischen Generierungsläufe sind vorhanden.',
        'remediation' => 'Führen Sie die Capell-Migrationen aus, um die Speichertabellen des HTML-Caches zu erstellen.',
    ],
];
