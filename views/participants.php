<?php
/**
 * Вкладка "Участники": отправитель, получатель, перевозчик.
 */
?>

    <section
        class="tab-panel"
        data-tab-panel="participants"
    >

        <div class="card">

            <h2>Участники ЭТрН</h2>


            <div class="small">
                Отправитель, получатель и перевозчик
                будут определяться из выбранной ЭТрН.
            </div>


            <div
                class="actions"
                style="margin-top:12px;"
            >

                <button
                    id="loadParticipantsButton"
                    type="button"
                    class="success"
                >
                    Загрузить участников
                </button>

            </div>


            <div
                id="participantsLoading"
                class="loading"
            >
                Загрузка участников...
            </div>


            <div
                id="participantsError"
                class="status error hidden"
            ></div>


            <div
                id="participantsData"
                class="hidden"
            >

                <h3>
                    Участники
                </h3>


                <div
                    id="participantsTable"
                    class="table-wrapper"
                ></div>


                <h3 style="margin-top:18px;">
                    Raw JSON участников
                </h3>


                <pre
                    id="participantsRawJson"
                    class="json"
                ></pre>

            </div>

        </div>

    </section>