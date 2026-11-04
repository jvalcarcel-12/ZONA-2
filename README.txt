APLICACIÓN COMMAND CENTER - PHP

Contenido:
- index.php: aplicación completa, adaptable a móvil y escritorio.
- .htaccess.example: ejemplo opcional de protección para Apache.
- README.txt: instrucciones de instalación.

REQUISITOS
- PHP 8.1 o superior (recomendado PHP 8.2+).
- Extensión cURL habilitada.
- Extensión Fileinfo habilitada.
- HTTPS en el sitio web.
- Bot de Telegram agregado al grupo y con permiso para publicar.

CONFIGURACIÓN
1. Suba index.php a una carpeta de su hosting con PHP.
2. Configure estas variables de entorno en el servidor:
   TELEGRAM_BOT_TOKEN = token NUEVO del bot
   TELEGRAM_CHAT_ID = -5374480595
3. No escriba el token dentro de index.php ni lo publique en el navegador.
4. Compruebe que PHP permita subir archivos:
   file_uploads = On
   upload_max_filesize = 10M
   post_max_size = 45M
   max_file_uploads = 4
5. Abra la dirección HTTPS donde subió index.php.

IMPORTANTE SOBRE EL TOKEN
El token que se compartió anteriormente en el chat debe considerarse comprometido.
Revóquelo con @BotFather y use el token nuevo únicamente como variable de entorno del hosting.
No lo envíe por chat ni lo guarde en archivos públicos.

FUNCIONAMIENTO
- Cuatro pantallas: identificación, control por hacienda, rondas/acompañamientos y novedades especiales.
- La zona acepta únicamente números.
- Se muestran las haciendas configuradas para las zonas 2 a 11.
- Permite agregar varias rondas y acompañamientos.
- Permite adjuntar hasta cuatro fotografías.
- Al enviar, PHP transmite el texto al grupo y luego las fotos como álbum.
- La página confirma el envío solo si Telegram responde correctamente.

NOTAS
- El identificador del grupo se dejó configurado como -5374480595 por defecto.
- Si el hosting no permite configurar variables de entorno desde el panel, solicite al proveedor que las configure para el sitio PHP.
- Revise límites de carga y tiempo de ejecución del hosting.
- Este paquete no instala ni configura el hosting por sí solo.
