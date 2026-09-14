<?php

declare(strict_types=1);

/**
 * Генератор XML-титула грузоотправителя ЭТрН (формат 5.01, TRNACLGROT).
 * Шаблон — живой документ SABY (docs/etrn_title_grot_sample.xml).
 *
 * Возвращает строку XML в windows-1251 (как требует формат).
 */

function etrnBuildTitleXml(array $d): string
{
    // $d: number, date, dep, dest, goods[], weight, size, quantity,
    //     receiverInn, receiverName, carrierInn, carrierName, carrierPhone,
    //     driverName, driverPhone, senderPhone (контакт Рармы), senderName

    $esc = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    // Дата/время формирования
    $dateDoc = (string)($d['date'] ?? date('d.m.Y'));
    $timeDoc = date('H:i:s');

    // Внешний ИдФайл можно задать (чтобы имя файла вложения совпадал)
    if (!empty($d['idFile'])) {
        $idFile = (string)$d['idFile'];
    } else {
        $guid = strtolower(sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        ));
        $idFile = 'ON_TRNACLGROT_' . $guid;
    }

    // --- Стороны ---
    $senderInn  = (string)($d['senderInn'] ?? OUR_INN);
    $senderKpp  = (string)($d['senderKpp'] ?? OUR_KPP);
    $senderName = (string)($d['senderName'] ?? OUR_NAME_SHORT);
    $senderFull = (string)($d['senderFull'] ?? OUR_NAME_FULL);

    $recInn  = (string)$d['receiverInn'];
    $recName = (string)($d['receiverName'] ?: ('ИНН ' . $recInn));
    $recFull = mb_strtoupper((string)($d['receiverFull'] ?? $recName));

    $carInn   = (string)($d['carrierInn'] ?? '');
    $carName  = (string)($d['carrierName'] ?? '');
    $carPhone = (string)($d['carrierPhone'] ?? '');

    $isIp = strlen($carInn) === 12;

    // --- Груз: строки через запятую + суммирование ---
    $items = array_values(array_filter(array_map('trim', explode(',', (string)$d['goods']))));
    if (!$items) {
        $items = ['Груз'];
    }
    $goodsText = implode(', ', $items);

    $weight = (float)str_replace(',', '.', (string)$d['weight']);
    $size   = (float)str_replace(',', '.', (string)($d['size'] ?? '0'));
    $qty    = (int)$d['quantity'];

    // --- Водитель: ФИО ---
    $fio = preg_split('/\s+/', trim((string)($d['driverName'] ?? '')));
    $drvFamily = $fio[0] ?? '';
    $drvName   = $fio[1] ?? '';
    $drvPatr   = $fio[2] ?? '';
    $drvPhone  = (string)($d['driverPhone'] ?? '');

    // --- Адреса ---
    $dep = (string)$d['dep'];
    $dest = (string)$d['dest'];

    // Адресация в XML: убрать служебные хвосты "|;|NNNN" и координаты
    $cleanAddr = static function (string $a): string {
        // Служебные хвосты "|;|5926" и "|5926" — СНАЧАЛА
        $a = preg_replace('/\|;\|\d+/', '', $a);
        $a = preg_replace('/\|\d+$/', '', $a);
        // Координаты "|52.6051488;39.5963775" (в любом месте строки)
        $a = preg_replace('/\|[\d\.\-;]+\|/', ' ', $a);
        $a = preg_replace('/\|[\d\.\-;]+$/', ' ', $a);
        // Кратные пробелы
        $a = preg_replace('/\s+/', ' ', $a);
        // Зависшие запятые/точки-запятые на конце
        return trim($a, " ,;|");
    };

    $depClean  = $cleanAddr((string)$d['dep']);
    $destClean = $cleanAddr((string)$d['dest']);

    $number = (string)($d['number'] ?? '') ?: 'БП-' . date('YmdHis');

    // Генерируем XML (без экранирования атрибутов вручную — через htmlspecialchars)
    $xml = <<<'XML'
<?xml version="1.0" encoding="WINDOWS-1251" ?>
<Файл ВерсПрог="saby-etrn-bp" ВерсФорм="5.01" ИдФайл="{IDFILE}">
  <Документ ВрИнфГО="{TIME}" ДатИнфГО="{DATE}" КНД="1110339" ПоФактХЖ="Транспортная накладная, информация грузоотправителя">
    <СодИнфГО ДатаЗак="{DATE}" ДатаТрН="{DATE}" НомерТрН="{NUMBER}" УИД_ТрН="{TRNUID}" СодОпер="Лицом, осуществляющим погрузку груза, при указанных обстоятельствах передан водителю груз с указанными характеристиками">
      <СвГО ГОЭксп="0">
        <РекИдентГО>
          <ИдСв>
            <СвЮЛУч ИННЮЛ="{SINN}" КПП="{SKPP}" НаимОрг="{SFULL}"/>
          </ИдСв>
          <Адрес>
            <АдрИнф АдрТекст="{DEP}" КодСтр="643"/>
          </Адрес>
        </РекИдентГО>
      </СвГО>
      <СвГП>
        <РекИдентГП>
          <ИдСв>
            <СвЮЛУч ИННЮЛ="{RINN}" КПП="{RKPP}" НаимОрг="{RNAME}"/>
          </ИдСв>
          <Адрес>
            <АдрИнф АдрТекст="{DEST}" КодСтр="643"/>
          </Адрес>
        </РекИдентГП>
        <АдресДостГр>
          <АдресИнф АдрТекст="{DEST}" КодСтр="643"/>
        </АдресДостГр>
      </СвГП>
      <СвГруз>
        <ОпГруз ВидТар="8A" КолМестГр="{QTY}" НаимГруз="{GOODS}" Объем="{SIZE}" СостГруз="без повреждений" СпУпак="Отсутствует">
          <Марк>Отсутствует</Марк>
          <ПлМасГруз МасБрутЗнач="{WEIGHT}"/>
        </ОпГруз>
      </СвГруз>
      <УказГО УкНормПрвз="Отсутствуют">
        <СвПА ЛицоПА="Грузоотправитель">
        </СвПА>
      </УказГО>
{CARRIER_SECTION}{DRIVER_SECTION}      <СвПогруз МетОпрМасс="01">
        <ФАдресПогр>
          <АдресИнф АдрТекст="{DEP}" КодСтр="643"/>
        </ФАдресПогр>
        <СвЛицПогрГр СовпГОП="1">
          <ИдентРекГО>
            <ИННЮЛ>{SINN}</ИННЮЛ>
          </ИдентРекГО>
        </СвЛицПогрГр>
        <ВладИнфр СовпГОВ="1">
          <ИдентРекГО>
            <ИННЮЛ>{SINN}</ИННЮЛ>
          </ИдентРекГО>
        </ВладИнфр>
      </СвПогруз>
    </СодИнфГО>
    <Подписант Должн="сотрудник" СтатПодп="2">
      <ФИО Имя="{PNAME}" Отчество="{PPATR}" Фамилия="{PFAM}"/>
    </Подписант>
  </Документ>
</Файл>
XML;

    // Перевозчик: ЮЛ или ИП
    if (strlen($carInn) === 12) {
        // ИП: ИННФЛ + ФИО из названия
        $cf = preg_split('/\s+/', $carName);
        $carrierBlock = '          <СвИП ИННФЛ="' . $carInn . '">'
            . '<ФИО Имя="' . htmlspecialchars($cf[1] ?? '', ENT_XML1 | ENT_QUOTES) . '"'
            . ' Отчество="' . htmlspecialchars(preg_replace('/\(.*$/', '', $cf[2] ?? ''), ENT_XML1 | ENT_QUOTES) . '"'
            . ' Фамилия="' . htmlspecialchars($cf[0] ?? '', ENT_XML1 | ENT_QUOTES) . '"/></СвИП>';
    } else {
        $carrierBlock = '          <СвЮЛ ИННЮЛ="' . htmlspecialchars($carInn, ENT_XML1 | ENT_QUOTES) . '"'
            . ' КПП="' . htmlspecialchars((string)($d['carrierKpp'] ?? ''), ENT_XML1 | ENT_QUOTES) . '"'
            . ' НаимОрг="' . htmlspecialchars($carName, ENT_XML1 | ENT_QUOTES) . '"/>';
    }

    // Секция перевозчика: только если есть данные (иначе SABY попросит заполнить в кабинете)
    if ($carInn !== '' || $carName !== '') {
        $carrierSection = "      <СвПер>\n        <ИдСв>\n" . $carrierBlock
            . "\n        </ИдСв>"
            . ($carPhone !== '' ? "\n        <Контакт>\n          <Тлф>" . htmlspecialchars($carPhone, ENT_XML1 | ENT_QUOTES) . "</Тлф>\n        </Контакт>" : '')
            . "\n      </СвПер>\n";
    } else {
        $carrierSection = '';
    }

    // Секция водителя: только если есть ФИО
    if ($drvFamily !== '' || $drvName !== '') {
        $driverSection = "      <СвВодит>\n"
            . ($drvPhone !== '' ? '        <Тлф>' . htmlspecialchars($drvPhone, ENT_XML1 | ENT_QUOTES) . '</Тлф>\n' : '')
            . '        <ФИО Имя="' . htmlspecialchars($drvName, ENT_XML1 | ENT_QUOTES) . '"'
            . ' Отчество="' . htmlspecialchars($drvPatr, ENT_XML1 | ENT_QUOTES) . '"'
            . ' Фамилия="' . htmlspecialchars($drvFamily, ENT_XML1 | ENT_QUOTES) . '"/>\n'
            . "      </СвВодит>\n";
    } else {
        $driverSection = '';
    }

    // Подписант: ответственный из БП или настройка
    $pf = preg_split('/\s+/', (string)($d['signerName'] ?? ''));
    $pFam  = $pf[0] ?? '';
    $pName = $pf[1] ?? '';
    $pPatr = $pf[2] ?? '';

    $map = [
        '{IDFILE}'    => $idFile,
        '{TIME}'      => $timeDoc,
        '{DATE}'      => $dateDoc,
        '{NUMBER}'    => htmlspecialchars((string)$d['number'] ?? '', ENT_XML1 | ENT_QUOTES),
        '{SINN}'      => $senderInn,
        '{SKPP}'      => $senderKpp,
        '{SFULL}'     => htmlspecialchars($senderFull, ENT_XML1 | ENT_QUOTES),
        '{TRNUID}'    => (string)($d['gisUid'] ?? strtolower(sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)))),
        '{RINN}'      => htmlspecialchars($recInn, ENT_XML1 | ENT_QUOTES),
        '{RKPP}'      => htmlspecialchars((string)($d['receiverKpp'] ?? ''), ENT_XML1 | ENT_QUOTES),
        '{CARRIER_SECTION}' => $carrierSection,
        '{DRIVER_SECTION}'  => $driverSection,
        '{RNAME}'     => htmlspecialchars($recFull, ENT_XML1 | ENT_QUOTES),
        '{RNAME2}'    => htmlspecialchars($recFull, ENT_XML1 | ENT_QUOTES),
        '{DEP}'       => htmlspecialchars($depClean, ENT_XML1 | ENT_QUOTES),
        '{DEST}'      => htmlspecialchars($destClean, ENT_XML1 | ENT_QUOTES),
        '{GOODS}'     => htmlspecialchars($goodsText, ENT_XML1 | ENT_QUOTES),
        '{QTY}'       => (string)$qty,
        '{SIZE}'      => rtrim(rtrim(number_format($size, 1, '.', ''), '0'), '.'),
        '{WEIGHT}'    => (string)(int)$weight,
        '{CARRIER_BLOCK}' => $carrierBlock,
        '{CPHONE}'    => htmlspecialchars($carPhone, ENT_XML1 | ENT_QUOTES),
        '{DPHONE}'    => htmlspecialchars($drvPhone, ENT_XML1 | ENT_QUOTES),
        '{DFAM}'      => htmlspecialchars($drvPatr === '' && $drvName === '' ? $drvName : ($drvName ?: ''), ENT_XML1 | ENT_QUOTES),
        '{DNAME}'     => htmlspecialchars($drvName ?: '', ENT_XML1 | ENT_QUOTES),
        '{DPATR}'     => htmlspecialchars($drvPatr ?: '', ENT_XML1 | ENT_QUOTES),
        '{PFAM}'      => htmlspecialchars($pFam, ENT_XML1 | ENT_QUOTES),
        '{PNAME}'     => htmlspecialchars($pName, ENT_XML1 | ENT_QUOTES),
        '{PPATR}'     => htmlspecialchars($pPatr, ENT_XML1 | ENT_QUOTES),
    ];

    // Водитель: ФИО — первый элемент = фамилия? В XML: Фамилия/Имя/Отчество.
    // Драйвер передаётся "Фамилия Имя Отчество": Фамилия=drvFamily
    $drvFamily = $fio[0] ?? '';
    $map['{DFAM}'] = htmlspecialchars($drvFamily, ENT_XML1 | ENT_QUOTES);
    $map['{DNAME}'] = htmlspecialchars($drvName, ENT_XML1 | ENT_QUOTES);
    $map['{DPATR}'] = htmlspecialchars($drvPatr, ENT_XML1 | ENT_QUOTES);

    $xml = str_replace(['{IDFILE}'], [$map['{IDFILE}']], $xml);
    $xml = strtr($xml, [
        '{TIME}' => $map['{TIME}'], '{DATE}' => $map['{DATE}'], '{NUMBER}' => $map['{NUMBER}'],
        '{SINN}' => $map['{SINN}'], '{SKPP}' => $map['{SKPP}'], '{SFULL}' => $map['{SFULL}'],
        '{TRNUID}' => $map['{TRNUID}'],
        '{RINN}' => $map['{RINN}'], '{RKPP}' => $map['{RKPP}'], '{RNAME}' => $map['{RNAME}'],
        '{DEP}' => $map['{DEP}'], '{DEST}' => $map['{DEST}'],
        '{GOODS}' => $map['{GOODS}'], '{QTY}' => $map['{QTY}'], '{SIZE}' => $map['{SIZE}'], '{WEIGHT}' => $map['{WEIGHT}'],
        '{CARRIER_SECTION}' => $map['{CARRIER_SECTION}'], '{DRIVER_SECTION}' => $map['{DRIVER_SECTION}'],
        '{CPHONE}' => $map['{CPHONE}'],
        '{DPHONE}' => $map['{DPHONE}'], '{DFAM}' => $map['{DFAM}'], '{DNAME}' => $map['{DNAME}'], '{DPATR}' => $map['{DPATR}'],
        '{PFAM}' => $map['{PFAM}'], '{PNAME}' => $map['{PNAME}'], '{PPATR}' => $map['{PPATR}'],
    ]);

    // Конвертируем в windows-1251 (формат требует)
    $cp1251 = @iconv('UTF-8', 'windows-1251', $xml);

    return $cp1251 !== false ? $cp1251 : $xml;
}