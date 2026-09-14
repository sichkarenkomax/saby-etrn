<?php

declare(strict_types=1);

/**
 * Резолвинг реквизитов компании Б24 по её ID:
 * ИНН и название — из реквизитов (crm.requisite.list, ENTITY_TYPE_ID 4),
 * телефон — из первичного контакта компании.
 */

/**
 * @return array{inn: string, name: string, phone: string}
 */
function b24ResolveCompany(string $webhook, string $companyId): array
{
    $out = ['inn' => '', 'kpp' => '', 'name' => '', 'phone' => ''];

    $companyId = (string)preg_replace('/[^0-9]/', '', $companyId);

    if ($companyId === '' || $webhook === '') {
        return $out;
    }

    // --- ИНН + название из реквизитов ---
    try {
        $resp = b24Call($webhook, 'crm.requisite.list', [
            'filter' => ['ENTITY_TYPE_ID' => 4, 'ENTITY_ID' => $companyId],
            'select' => ['ID', 'RQ_INN', 'RQ_COMPANY_NAME', 'NAME', 'RQ_KPP'],
        ]);
    } catch (Throwable $e) {
        return $out;
    }

    $items = (array)($resp['result'] ?? []);

    if ($items) {
        $rq = $items[0];
        $out['inn']  = (string)($rq['RQ_INN'] ?? '');
        $out['kpp']  = (string)($rq['RQ_KPP'] ?? '');
        $out['name'] = (string)($rq['RQ_COMPANY_NAME'] ?? $rq['NAME'] ?? '');
    }

    // --- Телефон из первичного контакта ---
    try {
        $contacts = b24Call($webhook, 'crm.company.contact.items.get', ['id' => (int)$companyId]);
        $contacts = (array)($contacts['result'] ?? []);

        $primaryId = 0;
        foreach ($contacts as $c) {
            if ((string)($c['IS_PRIMARY'] ?? 'N') === 'Y') {
                $primaryId = (int)$c['CONTACT_ID'];
                break;
            }
        }

        if (!$primaryId && $contacts) {
            $primaryId = (int)$contacts[0]['CONTACT_ID'];
        }

        if ($primaryId) {
            $cont = b24Call($webhook, 'crm.contact.get', [
                'id'     => $primaryId,
                'select' => ['PHONE'],
            ]);

            $phones = (array)($cont['result']['PHONE'] ?? []);

            if ($phones) {
                $out['phone'] = (string)($phones[0]['VALUE'] ?? '');
            }
        }
    } catch (Throwable $e) {
        // Телефон не критичен
    }

    return $out;
}