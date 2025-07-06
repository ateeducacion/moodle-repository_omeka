
# Uso correcto de phpcs y phpcbf con moodle-plugin-ci

Para analizar y corregir el código de tu plugin Moodle usando los estándares de Moodle, debes utilizar las herramientas `phpcs` (PHP CodeSniffer) y `phpcbf` (PHP Code Beautifier and Fixer) incluidas en el entorno de `moodle-plugin-ci`.

## Ejecución de phpcs

Para analizar archivos o directorios específicos y verificar que cumplen con los estándares de Moodle, ejecuta:

```bash
../moodle-plugin-ci/vendor/bin/phpcs --standard=moodle ./ruta/al/archivo_o_directorio.php
```

Esto mostrará los errores y advertencias de estilo detectados según el estándar de Moodle.

## Ejecución de phpcbf

Para intentar corregir automáticamente los errores de estilo detectados por `phpcs`, ejecuta:

```bash
../moodle-plugin-ci/vendor/bin/phpcbf --standard=moodle ./ruta/al/archivo_o_directorio.php
```

Esto modificará los archivos para corregir los problemas que puedan ser solucionados automáticamente.

## Notas

- Asegúrate de tener instalado `moodle-plugin-ci` mediante composer fuera del directorio de Moodle, por ejemplo:
  ```bash
  php composer.phar create-project moodlehq/moodle-plugin-ci ../moodle-plugin-ci ^4
  ```
- Si usas el archivo `.phar`, puedes ejecutar:
  ```bash
  php moodle-plugin-ci.phar phpcs ./ruta/al/archivo_o_directorio.php
  php moodle-plugin-ci.phar phpcbf ./ruta/al/archivo_o_directorio.php
  ```
- Consulta la documentación oficial de [moodle-plugin-ci](https://github.com/moodlehq/moodle-plugin-ci) para más detalles y opciones avanzadas.
