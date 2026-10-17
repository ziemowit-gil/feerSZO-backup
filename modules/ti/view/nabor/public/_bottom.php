<?php /** modules/ti/view/nabor/public/_bottom.php — stopka stron konta kandydata (domyka _top.php). */ ?>
  </main>
</div>
<?php
$ppOrg = $org;
$ppFootLinks = ['Zapisy na zajęcia' => '/nabor', 'Konto kandydata' => '/nabor/logowanie', 'Logowanie do panelu' => '/login.php', 'Strona Fundacji FEER' => 'https://feer.org.pl'];
include dirname(__DIR__, 3) . '/view/portal/_footer.php';
?>
</body>
</html>
