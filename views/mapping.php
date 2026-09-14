<?php
/**
 * Вкладка "Сопоставление полей".
 */
?>

    <section
        class="tab-panel"
        data-tab-panel="mapping"
    >

        <div
            id="mappingCard"
            class="card hidden"
        >

            <h2>
                Сопоставление полей
            </h2>


            <div class="status info">

                <span class="status-title">
                    Внимание:
                </span>

                <span class="status-subtitle">
                    Поля SABY ниже пока являются
                    логической схемой.
                    Перед отправкой ЭТрН их нужно заменить
                    точной схемой SABY API.
                </span>

            </div>


            <div
                id="mappingTable"
                class="table-wrapper"
            ></div>


            <div
                id="mappingSummary"
                class="mapping-summary"
            ></div>


            <div
                class="actions"
                style="margin-top:12px;"
            >

                <button
                    id="checkMappingButton"
                    type="button"
                    class="success"
                >
                    Проверить сопоставление
                </button>

            </div>


            <div
                id="mappingResult"
                class="status hidden"
                style="margin-top:12px;"
            ></div>

        </div>


        <div
            id="mappingEmpty"
            class="card"
        >

            <h2>
                Сопоставление полей
            </h2>

            <div class="status info">

                <span class="status-title">
                    Сначала выберите смарт-процесс
                </span>

                <span class="status-subtitle">
                    После загрузки его полей здесь появится
                    таблица сопоставления.
                </span>

            </div>

        </div>

    </section>