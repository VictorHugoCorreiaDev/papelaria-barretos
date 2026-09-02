    </div> <!-- content -->
    </div> <!-- main -->
    </div> <!-- layout -->

    <div id="toast" class="toast"></div>

    <?php
    // Mesmo motivo do CSS no header.php: o cache longo da hospedagem
    // seguraria a versão antiga do script por 30 dias
    $versaoJs = @filemtime(__DIR__ . '/../assets/js/funcoes.js') ?: '1';
    ?>
    <script src="/assets/js/funcoes.js?v=<?= $versaoJs ?>"></script>

</body>

</html>
