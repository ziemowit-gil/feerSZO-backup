# Konto seerwisowe
System umozliwia utworzenie kont serwisowyc, są one przeznaczone do zarzadzania systemem poza czynnościami związanymi z administracją pracy organizacji. Naley na nim wykonywać wyłącznie czynności techniczne związane z konfiguracją systemu
Konto serwisowe tworzy się skryptem PHP w lokalizacji: cli/CreateServiceUser.php
Domyślnie generuje konto z loginem: serwis@local i hasłem serwis. Bez kodu IKA (CPC)

# Konto ratunkowe
Powinno być uzywane wyłącznie w sytuacji gdy zostanie zablokowany dostęp administratora o odblokownaie go w tradycyjny sposób lub z poziomu CLI (unlock.php) nie jest mozliwe. 
Skrypt w lokalizacji: cli/emergency.php generuje konto:
**Login:**: ratunek@local
** Hasło: ratunek
Po zakończonej akcji usunąć konto: cli/emergency.php --remove 