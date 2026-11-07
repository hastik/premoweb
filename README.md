# premoweb

## Stahovac webu do lokalni struktury

Skript `download_site.php`:

- pri spusteni vyzada URL webu,
- proleze interni stranky (stejny host),
- ulozi HTML stranky do slozky `pages/`,
- stahne assety do `assets/` (obrazky do `assets/img`, CSS do `assets/css`, JS do `assets/js`, dalsi typy do dalsich podslozek),
- prepise odkazy v HTML na ciste URL od korene (`/`, `/xyz`, `/assets/...`),
- cte hierarchii stranek z `<body id="page-N">` a rodicu `page-N` v atributu `style`,
- vytvori `pages-tree.json` se stromovou strukturou stranek.

Soubor `.htaccess` zajisti na Apache:

- `/` → `pages/index.html`
- `/xyz` → `pages/xyz.html`
- `/assets/...` zustane beze zmeny

### Spusteni

```bash
php download_site.php
```
