# premoweb

## Stahovac webu do lokalni struktury

Skript `download_site.php`:

- pri spusteni vyzada URL webu,
- proleze interni stranky (stejny host),
- ulozi HTML stranky do slozky `pages/`,
- stahne assety do `assets/` (obrazky do `assets/img`, CSS do `assets/css`, JS do `assets/js`, dalsi typy do dalsich podslozek),
- prepise odkazy v HTML tak, aby fungovaly v lokalnim souborovem systemu.

### Spusteni

```bash
php download_site.php
```
