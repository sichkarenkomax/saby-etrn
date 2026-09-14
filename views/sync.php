<?php
/**
 * Вкладка "Синхронизация": статусы ЭТрН из SABY + сопоставление с Б24.
 */
?>

    <section
        class="tab-panel"
        data-tab-panel="sync"
    >

        <div class="card">

            <h2>
                Сопоставление с Bitrix24
            </h2>


            <div class="small">
                ЭТрН из SABY (все состояния, включая "в работе")
                сопоставляются с карточками смарт-процесса по паре ИНН
                (отправитель + получатель). Для сопоставленных карточек
                поля заполняются из титулов ЭТрН; в таймлайн пишутся
                только изменённые поля. Неоднозначные — в списке
                "Несопоставленные" для ручной привязки.
            </div>


            <div class="actions" style="margin-top:12px;">

                <button
                    id="matchButton"
                    type="button"
                    class="success"
                >
                    Сопоставить и обновить
                </button>

            </div>


            <div
                id="matchNotice"
                class="hidden"
                style="margin-top:8px;"
            ></div>


            <div
                id="matchLoading"
                class="loading"
            >
                Сопоставление...
            </div>


            <div
                id="matchError"
                class="status error hidden"
            ></div>


            <div
                id="matchData"
                class="hidden"
            ></div>

        </div>

    </section>