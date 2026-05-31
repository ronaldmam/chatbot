# Naldike Chatbot - Guía de Instalación y Uso

Este es un chatbot web desarrollado en PHP puro que utiliza la IA de Google Gemini para actuar como un asistente de ventas para la tienda Naldike.

## Requisitos Previos

1.  **Servidor Web con PHP**: XAMPP, WAMP, o PHP instalado en tu sistema.
2.  **API Key de Google Gemini**: Necesitas una clave válida.
3.  **Base de Datos MySQL**: Para guardar el historial de conversaciones.

## Configuración

1.  **Base de Datos**:
    *   Crea una base de datos en tu MySQL (ej. `chatbot_db`).
    *   Ejecuta el script `database_setup.sql` en esa base de datos para crear la tabla `chat_history`.

2.  **Archivos del Proyecto**: Asegúrate de tener los siguientes archivos en tu carpeta de proyecto:
    *   `index.html`: La interfaz del chat.
    *   `style.css`: Estilos del chat.
    *   `script.js`: Lógica del frontend (incluye el retardo de 10s).
    *   `config.php`: Configuración general.
    *   `chatbot.php`: Controlador principal del backend.
    *   `gemini_client.php`: Cliente para la API de Gemini.
    *   `scraper.php`: Scraper para obtener datos de naldike.com.

3.  **Configurar API Key y Base de Datos**:
    *   Abre el archivo `config.php`.
    *   Reemplaza `'YOUR_GEMINI_API_KEY'` con tu clave real.
    *   Configura las constantes `DB_HOST`, `DB_USER`, `DB_PASS`, y `DB_NAME` con tus credenciales de MySQL.

## Ejecución

### Opción A: Usando XAMPP (Recomendado si ya lo tienes)
1.  Copia la carpeta `chatbot` (o los archivos) a la carpeta `htdocs` de tu instalación de XAMPP (usualmente `C:\xampp\htdocs\chatbot`).
2.  Inicia el servicio **Apache** desde el panel de control de XAMPP.
3.  Abre tu navegador y ve a: `http://localhost/chatbot/`

### Opción B: Usando el servidor interno de PHP
1.  Abre una terminal (PowerShell o CMD).
2.  Navega a la carpeta donde están los archivos:
    ```powershell
    cd c:\PROYECTOS\ANTIGRAVITY\chatbot
    ```
3.  Ejecuta el siguiente comando:
    ```powershell
    php -S localhost:8000
    ```
4.  Abre tu navegador y ve a: `http://localhost:8000`

## Funcionalidades

*   **Chat de Ventas**: El bot actúa como un experto en ventas de Naldike.
*   **Información en Tiempo Real**: Consulta `naldike.com` para obtener precios y stock (simulado mediante scraping básico).
*   **Imágenes y Videos**: Si el bot encuentra imágenes, las mostrará directamente en el chat.
*   **Retardo Inteligente**: El chat espera 10 segundos después de que dejas de escribir para enviar todos tus mensajes juntos y dar una sola respuesta coherente.

## Personalización

*   **Prompt del Sistema**: Puedes modificar la personalidad del bot en `chatbot.php` (variable `$systemInstruction`).
*   **Estilos**: Edita `style.css` para cambiar colores y diseño.
*   **Lógica de Scraping**: Mejora `scraper.php` si cambia la estructura de `naldike.com`.
