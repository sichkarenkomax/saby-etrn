<?php
/**
 * Вкладка "ЭТрН": смарт-процесс, ID ЭТрН, данные ЭТрН, поля смарт-процесса.
 */
?>

    <section
        class="tab-panel"
        data-tab-panel="etrn"
    >

        <div class="card">

            <h2>ЭТрН</h2>


            <!-- ETRN ID -->

            <div class="field">

                <label for="etrnId">
                    ID ЭТрН
                </label>

                <input
                    id="etrnId"
                    type="number"
                    min="1"
                    placeholder="Например, 123"
                >

            </div>


            <div class="actions">

                <button
                    id="loadEtrnButton"
                    type="button"
                >
                    Загрузить данные ЭТрН
                </button>

            </div>


            <div
                id="etrnLoading"
                class="loading"
            >
                Загрузка данных ЭТрН...
            </div>


            <div
                id="etrnError"
                class="status error hidden"
            ></div>


            <div
                id="etrnData"
                class="hidden"
            >

                <h3>
                    Данные ЭТрН
                </h3>


                <div
                    id="etrnDataTable"
                    class="table-wrapper"
                ></div>


                <h3 style="margin-top:18px;">
                    Raw JSON
                </h3>


                <pre
                    id="etrnRawJson"
                    class="json"
                ></pre>

            </div>

        </div>


        <!-- Fields -->

        <div
            id="fieldsCard"
            class="card hidden"
        >

            <h2>
                Поля смарт-процесса
            </h2>


            <div
                id="fieldsError"
                class="status error hidden"
            ></div>


            <div
                id="fieldsLoading"
                class="loading"
            >
                Загрузка полей...
            </div>


            <div
                id="fieldsTable"
                class="table-wrapper"
            ></div>

        </div>

    </section>