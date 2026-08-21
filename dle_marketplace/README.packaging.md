# Сборка ZIP

Из корня репозитория выполните:

```bash
cd dle_marketplace
zip -r ../dle_marketplace.zip plugin.xml engine language plugins -x '*.DS_Store'
```

В архиве `plugin.xml` должен находиться в корне, а не внутри каталога `dle_marketplace`.
