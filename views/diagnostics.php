<?php
/**
 * Вкладка "Диагностика".
 */
?>

    <section
        class="tab-panel"
        data-tab-panel="diagnostics"
    >

        <div class="card">

            <h2>
                Журнал синхронизации
            </h2>


            <div class="small">
                Пишется автоматически. Последние события — сверху.
                Можно отключать категории записей и задавать лимит размера
                (файл перезаписывается при его достижении).
            </div>


            <div
                class="form-row"
                style="margin-top:10px; display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;"
            >

                <div class="field" style="width:140px;">

                    <label for="logLimitMb">
                        Лимит, Мб
                    </label>

                    <input
                        id="logLimitMb"
                        type="number"
                        min="1"
                        max="50"
                        step="1"
                        value="5"
                    >

                </div>


                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">

                    <span class="small">Писать в журнал:</span>

                    <label style="display:flex; align-items:center; gap:6px;"><input type="checkbox" id="logChInterval" style="margin:0; width:auto; flex:0 0 auto;"> Пропуски по интервалу</label>

                    <label style="display:flex; align-items:center; gap:6px;"><input type="checkbox" id="logChSync" style="margin:0; width:auto; flex:0 0 auto;"> Синхронизация</label>

                    <label style="display:flex; align-items:center; gap:6px;"><input type="checkbox" id="logChActivity" style="margin:0; width:auto; flex:0 0 auto;"> Активити</label>

                    <label style="display:flex; align-items:center; gap:6px;"><input type="checkbox" id="logChNotify" style="margin:0; width:auto; flex:0 0 auto;"> Уведомления</label>

                </div>


                <button
                    id="logRefresh"
                    type="button"
                >
                    Обновить
                </button>


                <button
                    id="logSave"
                    type="button"
                    class="success"
                >
                    Сохранить лимит
                </button>

            </div>


            <div
                class="small"
                id="logMeta"
                style="margin-top:6px;"
            ></div>


            <pre
                id="logView"
                style="margin-top:8px; max-height:360px; overflow:auto; background:#0f172a; color:#e2e8f0; padding:10px; border-radius:6px; font-size:12px; white-space:pre-wrap;"
            >—</pre>

        </div>

    </section>

</div>




<!-- =========================================================
     JS
     ========================================================= -->

<script src="//api.bitrix24.com/api/v1/"></script>

<script src="../assets/js/helpers.js"></script>

<script src="../assets/js/state.js"></script>

<script src="../assets/js/etrn.js"></script>

<script src="../assets/js/participants.js"></script>

<script src="../assets/js/sync.js"></script>

<script src="../assets/js/match.js"></script>

<script src="../assets/js/settings.js"></script>

<script src="../assets/js/activity.js"></script>

<script src="../assets/js/diaglog.js"></script>

<script src="../assets/js/app.js"></script>


</body>
</html>