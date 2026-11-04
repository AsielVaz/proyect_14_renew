<?php
function generarCorreoFapa($texto)
{
  return <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cotización FAPA</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Barlow:wght@400;500;600;700&family=Barlow+Condensed:wght@600;700&display=swap');

    /* Estilos generales seguros para correo */
    body {
      background-color: #f0f2f5;
      margin: 0;
      padding: 30px 10px;
      font-family: 'Barlow', Arial, sans-serif;
      -webkit-font-smoothing: antialiased;
    }

    /* Estilos para el contenido inyectado ($texto) */
    .email-message {
      color: #3a3a4a;
      font-size: 14.5px;
      line-height: 1.7;
    }
    .email-message p { 
      margin-top: 0;
      margin-bottom: 14px; 
    }
    .email-message p:last-child { 
      margin-bottom: 0; 
    }
    
    /* Estilos elegantes para las tablas inyectadas en $texto */
    .email-message table {
      width: 100% !important;
      border-collapse: collapse !important;
      margin: 16px 0 !important;
      font-size: 13.5px !important;
    }
    .email-message table th {
      background-color: #1a1a2e !important;
      color: #ffffff !important;
      padding: 10px 12px !important;
      text-align: left !important;
      font-family: 'Barlow Condensed', Arial, sans-serif !important;
      text-transform: uppercase !important;
      letter-spacing: 0.5px !important;
    }
    .email-message table td {
      padding: 10px 12px !important;
      border: 1px solid #e8eaed !important;
    }

    /* Firma con fuentes estilizadas */
    .firma-title {
      font-family: 'Barlow Condensed', Arial, sans-serif;
      font-size: 17px;
      font-weight: 700;
      color: #1a1a2e;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin: 0 0 2px 0;
    }
    .firma-sub {
      font-family: 'Barlow Condensed', Arial, sans-serif;
      font-size: 11px;
      color: #e8260a;
      font-weight: 600;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      margin: 0 0 12px 0;
    }
  </style>
</head>
<body>

  <!-- Contenedor Principal Elegante -->
  <table cellpadding="0" cellspacing="0" border="0" align="center" style="max-width: 680px; width: 100%; background-color: #ffffff; border-radius: 6px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); border: 1px solid #e8eaed; overflow: hidden; margin: 0 auto;">
    
    <!-- Cuerpo del Correo -->
    <tr>
      <td class="email-message" style="padding: 32px 36px; background-color: #ffffff;">
        $texto
      </td>
    </tr>

    <!-- Separador sutil -->
    <tr>
      <td style="padding: 0 36px;">
        <hr style="border: none; border-top: 1px solid #e0e3e8; margin: 0;" />
      </td>
    </tr>

    <!-- Firma corporativa -->
    <tr>
      <td style="padding: 28px 36px 32px; background-color: #ffffff;">
        <table cellpadding="0" cellspacing="0" border="0" style="width: 100%;">
          <tr>
            <!-- Columna Logo -->
            <td style="padding-right: 24px; border-right: 3px solid #e8260a; vertical-align: middle; text-align: center; width: 120px;">
              <img src="https://fapa.mx/images/logo_fapa.png" alt="FAPA" width="105" style="display: block; margin: 0 auto; height: auto;" />
              <p style="margin: 8px 0 0 0; font-size: 9.5px; color: #888888; letter-spacing: 1.5px; text-transform: uppercase; font-family: 'Barlow Condensed', Arial, sans-serif; font-weight: 600;">
                Transporte &amp; Logística
              </p>
            </td>

            <!-- Columna Datos -->
            <td style="padding-left: 24px; vertical-align: middle;">
              <p class="firma-title">Departamento de facturación</p>
              <p class="firma-sub">FAPA TRANSFERS</p>

              <table cellpadding="0" cellspacing="0" border="0" style="width: 100%;">
                <tr>
                  <td style="padding-bottom: 6px; font-size: 13px; color: #4a4a5a;">
                    📞 <a href="tel:3315486236" style="color: #1a6bcc; text-decoration: none; font-weight: 500;">33 1548 6236</a>
                  </td>
                </tr>
                <tr>
                  <td style="padding-bottom: 6px; font-size: 13px; color: #4a4a5a;">
                    ✉️ <a href="mailto:contacto@fapa.mx" style="color: #1a6bcc; text-decoration: none; font-weight: 500;">contacto@fapa.mx</a>
                  </td>
                </tr>
                <tr>
                  <td style="padding-bottom: 6px; font-size: 13px; color: #4a4a5a;">
                    📍 Pablo Villaseñor #128, Col. Arcos Vallarta, Guadalajara, Jalisco
                  </td>
                </tr>
                <tr>
                  <td style="padding-bottom: 12px; font-size: 13px; color: #4a4a5a;">
                    🌐 <a href="https://fapa.mx" target="_blank" style="color: #1a6bcc; text-decoration: none; font-weight: 500;">www.fapa.mx</a>
                  </td>
                </tr>
              </table>

              <table cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="background-color: #1a1a2e; padding: 5px 12px; border-radius: 3px;">
                    <span style="color: #ffffff; font-size: 10px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; font-family: 'Barlow Condensed', Arial, sans-serif;">
                      ● <span style="color: #e8260a;">FAPA</span> — Transporte Confiable
                    </span>
                  </td>
                </tr>
              </table>

            </td>
          </tr>
        </table>
      </td>
    </tr>

  </table>

</body>
</html>
HTML;
}

function generaCorreoCodigo($texto)
{
  return <<<HTML
          <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="es">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="X-UA-Compatible" content="IE=edge" />
<meta name="x-apple-disable-message-reformatting" />
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no" />
<meta name="color-scheme" content="light" />
<title>Código y Chips – Desarrollo de Software a la Medida</title>
<!--[if mso]>
<xml><o:OfficeDocumentSettings><o:AllowPNG/><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml>
<![endif]-->
</head>
<body style="margin:0;padding:0;width:100%;background-color:#f0f0f0;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">

<!-- Preheader (texto de vista previa) -->
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">
  Software a la medida para impulsar tu negocio. Conoce cómo podemos ayudarte.&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
</div>

<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="#f0f0f0" style="background-color:#f0f0f0;margin:0;padding:0;">
  <tr>
    <td align="center" style="padding:30px 10px;">

      <!--[if mso]>
      <table role="presentation" width="600" align="center" border="0" cellspacing="0" cellpadding="0"><tr><td>
      <![endif]-->

      <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="#ffffff" style="width:100%;max-width:600px;background-color:#ffffff;margin:0 auto;">

        <!-- BARRA SUPERIOR -->
        <tr>
          <td height="5" bgcolor="#555555" style="height:5px;line-height:5px;font-size:0;background-color:#555555;background-image:linear-gradient(90deg,#1a1a1a,#555555,#999999);">&nbsp;</td>
        </tr>

        <!-- ENCABEZADO -->
        <tr>
          <td style="padding:28px 30px 24px 30px;">
            <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0">
              <tr>
                <td align="left" valign="middle" style="font-family:Arial,Helvetica,sans-serif;">
                  <a href="https://www.codigoychips.com" target="_blank" style="text-decoration:none;">
                    <img src="https://www.codigoychips.com/logo.png" alt="Código y Chips – Desarrollo de Software" width="110" style="display:block;width:110px;max-width:110px;height:auto;border:0;outline:none;text-decoration:none;" />
                  </a>
                </td>
                <td align="right" valign="middle" style="font-family:Arial,Helvetica,sans-serif;">
                  <table role="presentation" border="0" cellspacing="0" cellpadding="0" align="right">
                    <tr>
                      <td bgcolor="#2a2a2a" style="background-color:#2a2a2a;border-radius:20px;padding:5px 12px;font-family:Arial,Helvetica,sans-serif;font-size:9px;line-height:12px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;color:#ffffff;">Software</td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- HERO -->
        <tr>
          <td bgcolor="#111111" style="background-color:#111111;padding:48px 30px 44px 30px;" align="left">
            <p style="margin:0 0 14px 0;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:16px;letter-spacing:3px;text-transform:uppercase;color:#999999;">Desarrollo de software a la medida</p>
            <h1 style="margin:0 0 16px 0;font-family:Arial,Helvetica,sans-serif;font-size:32px;line-height:38px;font-weight:bold;color:#ffffff;letter-spacing:-0.5px;">Convertimos tus ideas en software que funciona.</h1>
            <p style="margin:0 0 28px 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#bbbbbb;">Diseñamos y desarrollamos soluciones tecnológicas a la medida de tu negocio: simples, escalables y listas para crecer contigo.</p>
            <!-- Botón -->
            <table role="presentation" border="0" cellspacing="0" cellpadding="0">
              <tr>
                <td align="center" bgcolor="#ffffff" style="background-color:#ffffff;border-radius:4px;">
                  <a href="https://www.codigoychips.com" target="_blank" style="display:inline-block;padding:14px 32px;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:18px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:#111111;text-decoration:none;border-radius:4px;">Conoce más</a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- INTRODUCCIÓN -->
        <tr>
          <td style="padding:40px 30px 12px 30px;" align="left">
            <h2 style="margin:0 0 12px 0;font-family:Arial,Helvetica,sans-serif;font-size:20px;line-height:26px;font-weight:bold;color:#111111;letter-spacing:-0.3px;">Saludos</h2>
            <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:23px;color:#555555;">$texto</p>
          </td>
        </tr>

        <!-- SERVICIOS (columnas híbridas, se apilan en móvil) -->
        <tr>
          <td align="center" style="padding:24px 20px 10px 20px;font-size:0;">
            <!--[if mso]>
            <table role="presentation" width="560" border="0" cellspacing="0" cellpadding="0"><tr><td width="186" valign="top">
            <![endif]-->
            <div style="display:inline-block;width:100%;max-width:186px;vertical-align:top;">
              <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0">
                <tr>
                  <td style="padding:0 5px 10px 5px;">
                    <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="#f7f7f7" style="background-color:#f7f7f7;border-top:3px solid #1a1a1a;">
                      <tr>
                        <td align="left" style="padding:20px 16px;">
                          <p style="margin:0 0 8px 0;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:14px;font-weight:bold;letter-spacing:2px;color:#999999;">01</p>
                          <p style="margin:0 0 8px 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:20px;font-weight:bold;color:#111111;">Sistemas a la medida</p>
                          <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:12.5px;line-height:19px;color:#666666;">Plataformas web y de escritorio diseñadas para tus procesos.</p>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>
            </div>
            <!--[if mso]></td><td width="186" valign="top"><![endif]-->
            <div style="display:inline-block;width:100%;max-width:186px;vertical-align:top;">
              <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0">
                <tr>
                  <td style="padding:0 5px 10px 5px;">
                    <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="#f7f7f7" style="background-color:#f7f7f7;border-top:3px solid #555555;">
                      <tr>
                        <td align="left" style="padding:20px 16px;">
                          <p style="margin:0 0 8px 0;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:14px;font-weight:bold;letter-spacing:2px;color:#999999;">02</p>
                          <p style="margin:0 0 8px 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:20px;font-weight:bold;color:#111111;">Apps y automatización</p>
                          <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:12.5px;line-height:19px;color:#666666;">Integraciones y flujos que ahorran tiempo y reducen errores.</p>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>
            </div>
            <!--[if mso]></td><td width="186" valign="top"><![endif]-->
            <div style="display:inline-block;width:100%;max-width:186px;vertical-align:top;">
              <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0">
                <tr>
                  <td style="padding:0 5px 10px 5px;">
                    <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="#f7f7f7" style="background-color:#f7f7f7;border-top:3px solid #999999;">
                      <tr>
                        <td align="left" style="padding:20px 16px;">
                          <p style="margin:0 0 8px 0;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:14px;font-weight:bold;letter-spacing:2px;color:#999999;">03</p>
                          <p style="margin:0 0 8px 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:20px;font-weight:bold;color:#111111;">Soporte y mejora continua</p>
                          <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:12.5px;line-height:19px;color:#666666;">Acompañamiento constante para que tu software evolucione.</p>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>
            </div>
            <!--[if mso]></td></tr></table><![endif]-->
          </td>
        </tr>

        <!-- LLAMADO A LA ACCIÓN SECUNDARIO -->
        <tr>
          <td style="padding:20px 30px 40px 30px;" align="left">
            <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0">
              <tr>
                <td style="border-left:3px solid #1a1a1a;padding:4px 0 4px 18px;">
                  <p style="margin:0 0 6px 0;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:22px;font-weight:bold;color:#111111;">¿Tienes un proyecto en mente?</p>
                  <p style="margin:0 0 16px 0;font-family:Arial,Helvetica,sans-serif;font-size:13.5px;line-height:21px;color:#666666;">Cuéntanos tu idea y te compartiremos una propuesta sin compromiso.</p>
                  <table role="presentation" border="0" cellspacing="0" cellpadding="0">
                    <tr>
                      <td align="center" bgcolor="#111111" style="background-color:#111111;border-radius:4px;">
                        <a href="mailto:contacto@codigoychips.com" style="display:inline-block;padding:12px 26px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:16px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:#ffffff;text-decoration:none;border-radius:4px;">Escríbenos</a>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- SEPARADOR -->
        <tr>
          <td style="padding:0 30px;"><table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0"><tr><td height="1" bgcolor="#efefef" style="height:1px;line-height:1px;font-size:0;background-color:#efefef;">&nbsp;</td></tr></table></td>
        </tr>

        <!-- FIRMA / CONTACTO -->
        <tr>
          <td style="padding:28px 30px;">
            <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0">
              <tr>
                <td valign="top" align="left" style="font-family:Arial,Helvetica,sans-serif;">
                  <!--[if mso]><table role="presentation" width="540" border="0" cellspacing="0" cellpadding="0"><tr><td width="150" valign="middle"><![endif]-->
                  <div style="display:inline-block;width:100%;max-width:150px;vertical-align:middle;">
                    <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0">
                      <tr>
                        <td align="left" style="padding:0 0 14px 0;">
                          <img src="https://www.codigoychips.com/logo.png" alt="Logotipo Código y Chips" width="100" style="display:block;width:100px;max-width:100px;height:auto;border:0;outline:none;text-decoration:none;" />
                          <table role="presentation" border="0" cellspacing="0" cellpadding="0" style="margin-top:10px;">
                            <tr>
                              <td bgcolor="#2a2a2a" style="background-color:#2a2a2a;border-radius:20px;padding:4px 10px;font-family:Arial,Helvetica,sans-serif;font-size:8.5px;line-height:11px;font-weight:bold;letter-spacing:1.8px;text-transform:uppercase;color:#ffffff;">Software</td>
                            </tr>
                          </table>
                        </td>
                      </tr>
                    </table>
                  </div>
                  <!--[if mso]></td><td width="390" valign="middle"><![endif]-->
                  <div style="display:inline-block;width:100%;max-width:380px;vertical-align:middle;">
                    <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0">
                      <tr>
                        <td style="padding:0 0 0 0;font-family:Arial,Helvetica,sans-serif;">
                          <p style="margin:0 0 12px 0;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:16px;font-weight:bold;letter-spacing:0.5px;color:#777777;">Desarrollo de Software a la Medida</p>
                          <p style="margin:0 0 8px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:19px;color:#444444;"><span style="font-size:10px;font-weight:bold;letter-spacing:1.5px;text-transform:uppercase;color:#999999;">Tel&nbsp;&nbsp;</span><a href="tel:+523319043727" style="color:#444444;text-decoration:none;">+52 (33) 1904-3727</a></p>
                          <p style="margin:0 0 8px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:19px;color:#444444;"><span style="font-size:10px;font-weight:bold;letter-spacing:1.5px;text-transform:uppercase;color:#999999;">Mail&nbsp;</span><a href="mailto:contacto@codigoychips.com" style="color:#444444;text-decoration:none;">contacto@codigoychips.com</a></p>
                          <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:19px;color:#444444;"><span style="font-size:10px;font-weight:bold;letter-spacing:1.5px;text-transform:uppercase;color:#999999;">Dir&nbsp;&nbsp;&nbsp;</span>Pablo Villaseñor #128, Col. Arcos Vallarta<br />Guadalajara, Jalisco, México</p>
                        </td>
                      </tr>
                    </table>
                  </div>
                  <!--[if mso]></td></tr></table><![endif]-->
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- FOOTER -->
        <tr>
          <td bgcolor="#0d0d0d" style="background-color:#0d0d0d;padding:16px 30px;">
            <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0">
              <tr>
                <td align="left" valign="middle" style="font-family:Arial,Helvetica,sans-serif;font-size:10px;line-height:14px;letter-spacing:2px;text-transform:uppercase;color:#666666;">GDL <span style="color:#aaaaaa;">&middot;</span> Jalisco <span style="color:#aaaaaa;">&middot;</span> MX</td>
                <td align="right" valign="middle" style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:14px;">
                  <span style="color:#444444;">&#9679;</span>&nbsp;<span style="color:#777777;">&#9679;</span>&nbsp;<span style="color:#bbbbbb;">&#9679;</span>
                </td>
              </tr>
            </table>
          </td>
        </tr>

      </table>

      <!--[if mso]></td></tr></table><![endif]-->

      <!-- AVISO LEGAL -->
      <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;margin:0 auto;">
        <tr>
          <td align="center" style="padding:22px 20px 0 20px;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:17px;color:#999999;">
            Recibes este correo porque estás en contacto con Código y Chips.<br />
            Pablo Villaseñor #128, Col. Arcos Vallarta, Guadalajara, Jalisco, México.<br />
            <a href="https://www.codigoychips.com" target="_blank" style="color:#777777;text-decoration:underline;">Aviso de privacidad</a>
            &nbsp;&middot;&nbsp;
            <a href="mailto:contacto@codigoychips.com?subject=Baja%20de%20correos" style="color:#777777;text-decoration:underline;">Cancelar suscripción</a>
          </td>
        </tr>
      </table>

    </td>
  </tr>
</table>

</body>
</html>

HTML;
}


function generaCorreoDelment($mensaje)
{
  return <<<HTML
    <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="es">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Correo Electrónico – Delment Trading</title>
  <!--[if mso]>
  <noscript>
    <xml>
      <o:OfficeDocumentSettings>
        <o:PixelsPerInch>96</o:PixelsPerInch>
      </o:OfficeDocumentSettings>
    </xml>
  </noscript>
  <![endif]-->
</head>
<body style="margin:0; padding:0; background-color:#eef2ff; font-family:Arial, Helvetica, sans-serif;">

  <!-- WRAPPER -->
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#eef2ff;">
    <tr>
      <td align="center" style="padding:32px 16px;">

        <!-- EMAIL CARD -->
        <table role="presentation" width="620" cellpadding="0" cellspacing="0" border="0" style="max-width:620px; width:100%; background-color:#ffffff; border-radius:12px; overflow:hidden;">

          <!-- ── CABECERA ── -->
          <tr>
            <td style="background-color:#5b35d5; padding:32px 40px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td>
                    <!-- Logo + Nombre -->
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                      <tr>
                        <td style="padding-right:14px; vertical-align:middle;">
                          <img src="https://delment-trading.com/img/logo.png"
                               alt="Delment Trading"
                               width="48" height="48"
                               style="display:block; width:48px; height:48px; border-radius:8px;" />
                        </td>
                        <td style="vertical-align:middle;">
                          <p style="margin:0; font-size:20px; font-weight:bold; color:#ffffff; font-family:Arial, Helvetica, sans-serif;">Delment Trading</p>
                          <p style="margin:2px 0 0 0; font-size:10px; color:rgba(255,255,255,0.70); letter-spacing:2px; text-transform:uppercase; font-family:Arial, Helvetica, sans-serif;">COMERCIALIZACIÓN</p>
                        </td>
                      </tr>
                    </table>
                  </td>
                  <td align="right" style="vertical-align:middle;">
                    <span style="display:inline-block; background-color:rgba(255,255,255,0.18); border:1px solid rgba(255,255,255,0.30); border-radius:20px; padding:5px 14px; font-size:10px; font-weight:bold; color:#ffffff; letter-spacing:1.5px; text-transform:uppercase; font-family:Arial, Helvetica, sans-serif;">GLOBAL TRADE</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- ── BANDA DE COLOR ── -->
          <tr>
            <td height="4" style="background-color:#3b82f6; font-size:0; line-height:0;">&nbsp;</td>
          </tr>

          <!-- ── CUERPO ── -->
          <tr>
            <td style="padding:44px 40px 32px;">

              <!-- Saludo -->
              <p style="margin:0 0 18px 0; font-size:20px; font-weight:bold; color:#1e1b4b; font-family:Arial, Helvetica, sans-serif;">¡Hola 👋</p>

              <!-- Párrafo 1 -->
              <p style="margin:0 0 16px 0; font-size:15px; line-height:1.8; color:#4b5563; font-family:Arial, Helvetica, sans-serif;">
                Nos complace ponernos en contacto con usted en nombre de
                <strong style="color:#1e1b4b;">Delment Trading</strong>.
                Nos especializamos en la comercialización de productos de alta calidad, ofreciendo
                soluciones integrales adaptadas a las necesidades de cada cliente.
              </p>

              <!-- Placeholder -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0;">
                <tr>
                  <td width="4" style="background-color:#5b35d5; border-radius:2px;">&nbsp;</td>
                  <td style="background-color:#f5f3ff; padding:16px 18px; border-radius:0 8px 8px 0;">
                    <p style="margin:0; font-size:14px; line-height:1.75; color:#7c6aff; font-style:italic; font-family:Arial, Helvetica, sans-serif;">
                      ✏️ &nbsp; $mensaje  
                    </p>
                  </td>
                </tr>
              </table>

              <!-- Párrafo 2 -->
              <p style="margin:0 0 28px 0; font-size:15px; line-height:1.8; color:#4b5563; font-family:Arial, Helvetica, sans-serif;">
                Quedamos a su entera disposición para cualquier consulta o requerimiento adicional.
                No dude en contactarnos; será un placer asistirle.
              </p>

              <!-- CTA -->
              <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td align="center" style="background-color:#5b35d5; border-radius:30px;">
                    <a href="mailto:contacto@delment-trading.com"
                       style="display:inline-block; padding:13px 36px; font-size:12px; font-weight:bold; color:#ffffff; text-decoration:none; letter-spacing:1px; text-transform:uppercase; font-family:Arial, Helvetica, sans-serif;">
                      CONTÁCTENOS →
                    </a>
                  </td>
                </tr>
              </table>

            </td>
          </tr>

          <!-- ── DIVISOR ── -->
          <tr>
            <td style="padding:0 40px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td height="1" style="background-color:#e0e7ff; font-size:0; line-height:0;">&nbsp;</td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- ── FIRMA ── -->
          <tr>
            <td style="padding:28px 40px 36px;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <!-- Avatar -->
                  <td width="54" style="vertical-align:top; padding-right:18px;">
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                      <tr>
                        <td width="54" height="54" align="center" style="background-color:#5b35d5; border-radius:50%; vertical-align:middle;">
                          <span style="font-size:22px; font-weight:bold; color:#ffffff; font-family:Arial, Helvetica, sans-serif; line-height:54px;">D</span>
                        </td>
                      </tr>
                    </table>
                  </td>
                  <!-- Info -->
                  <td style="vertical-align:top;">
                    <p style="margin:0; font-size:16px; font-weight:bold; color:#1e1b4b; font-family:Arial, Helvetica, sans-serif;">Departamento de Ventas</p>
                    <p style="margin:2px 0 14px 0; font-size:10px; font-weight:bold; letter-spacing:2px; text-transform:uppercase; color:#5b35d5; font-family:Arial, Helvetica, sans-serif;">DELMENT TRADING</p>

                    <!-- Email -->
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:8px;">
                      <tr>
                        <td width="28" height="28" align="center" style="background-color:#f0ebff; border-radius:6px; vertical-align:middle; padding-right:8px;">
                          <img src="https://cdn-icons-png.flaticon.com/16/561/561127.png" alt="" width="14" height="14" style="display:block;" />
                        </td>
                        <td>
                          <a href="mailto:contacto@delment-trading.com" style="font-size:13px; color:#6b7280; text-decoration:none; font-family:Arial, Helvetica, sans-serif;">contacto@delment-trading.com</a>
                        </td>
                      </tr>
                    </table>

                    <!-- Web -->
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                      <tr>
                        <td width="28" height="28" align="center" style="background-color:#e0f2fe; border-radius:6px; vertical-align:middle; padding-right:8px;">
                          <img src="https://cdn-icons-png.flaticon.com/16/364/364089.png" alt="" width="14" height="14" style="display:block;" />
                        </td>
                        <td>
                          <a href="https://delment-trading.com" target="_blank" style="font-size:13px; color:#6b7280; text-decoration:none; font-family:Arial, Helvetica, sans-serif;">delment-trading.com</a>
                        </td>
                      </tr>
                    </table>

                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- ── FOOTER ── -->
          <tr>
            <td style="background-color:#1e1b4b; padding:18px 40px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td>
                    <p style="margin:0; font-size:10px; color:rgba(255,255,255,0.45); line-height:1.65; font-family:Arial, Helvetica, sans-serif;">
                      © 2025 Delment Trading. Todos los derechos reservados.<br/>
                      Comercialización · Distribución · Importación &amp; Exportación
                    </p>
                  </td>
                  <td align="right">
                    <img src="https://delment-trading.com/img/logo.png"
                         alt="Delment Trading"
                         height="26"
                         style="display:block; height:26px; opacity:0.35;" />
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- ── DISCLAIMER ── -->
          <tr>
            <td style="background-color:#f8f9ff; border-top:1px solid #e9eeff; padding:12px 40px 16px;">
              <p style="margin:0; font-size:9px; color:#b0b8cc; line-height:1.65; text-align:center; font-family:Arial, Helvetica, sans-serif;">
                Este correo electrónico y sus adjuntos son confidenciales y están dirigidos exclusivamente al destinatario indicado.
                Si usted no es el destinatario previsto, por favor notifíquelo al remitente y elimine este mensaje.
                Delment Trading no se hace responsable del uso no autorizado de esta comunicación.
              </p>
            </td>
          </tr>

        </table>
        <!-- /EMAIL CARD -->

      </td>
    </tr>
  </table>

</body>
</html>
HTML;
}


function generaCorreoEdworld($mensaje)
{
  return <<<HTML
    <!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Correo Corporativo - Edworld</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Lato:wght@300;400;700&display=swap');

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      background-color: #f0f2f5;
      font-family: 'Lato', Arial, sans-serif;
    }
  </style>
</head>
<body>

<!-- ===== WRAPPER PRINCIPAL ===== -->
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f0f2f5; padding: 32px 16px;">
  <tr>
    <td align="center">

      <!-- CONTENEDOR DEL CORREO -->
      <table width="620" cellpadding="0" cellspacing="0" border="0"
             style="max-width:620px; width:100%; border-radius:4px; overflow:hidden;
                    box-shadow: 0 4px 24px rgba(0,0,0,0.12);">

        <!-- ============================================================ -->
        <!-- CABECERA / HEADER                                            -->
        <!-- ============================================================ -->
        <tr>
          <td style="background-color:#0d1b2e; padding: 0;">

            <!-- Franja dorada superior -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="background-color:#c9a84c; height:5px; font-size:0; line-height:0;">&nbsp;</td>
              </tr>
            </table>

            <!-- Logo + nombre empresa -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="padding: 32px 40px 28px 40px;" align="center">
                  <img src="https://edworld.mx/logo.png"
                       alt="Edworld Logo"
                       width="170"
                       style="display:block; margin: 0 auto 12px auto; max-width:170px;" />
                  <p style="font-family:'Lato',Arial,sans-serif; font-size:11px; letter-spacing:3px;
                             text-transform:uppercase; color:#c9a84c; margin:0;">
                    Soluciones Administrativas Integrales S.C.
                  </p>
                </td>
              </tr>
            </table>

            <!-- Línea separadora dorada -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="padding: 0 40px;">
                  <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                      <td style="background-color:#c9a84c; height:1px; font-size:0; line-height:0;">&nbsp;</td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>

            <!-- Tagline / Servicio -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="padding: 18px 40px 32px 40px;" align="center">
                  <p style="font-family:'Lato',Arial,sans-serif; font-size:12px; letter-spacing:2px;
                             text-transform:uppercase; color:#7a9bb8; margin:0;">
                    Administración Legal &nbsp;·&nbsp; Contable &nbsp;·&nbsp; Administrativa
                  </p>
                </td>
              </tr>
            </table>

          </td>
        </tr>

        <!-- ============================================================ -->
        <!-- CUERPO / BODY                                                -->
        <!-- ============================================================ -->
        <tr>
          <td style="background-color:#ffffff; padding: 48px 40px 40px 40px;">

            <!-- Saludo -->
            <p style="font-family:'Lato',Arial,sans-serif; font-size:15px; color:#0d1b2e;
                       font-weight:700; margin-bottom:6px; letter-spacing:0.3px;">
              Estimado/a:
            </p>

            <!-- Separador decorativo -->
            <table width="48" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:22px;">
              <tr>
                <td style="background-color:#c9a84c; height:3px; border-radius:2px; font-size:0;">&nbsp;</td>
              </tr>
            </table>

           

            <p style="font-family:'Lato',Arial,sans-serif; font-size:14.5px; line-height:1.8;
                       color:#3a4556; margin-bottom:20px;">
              $mensaje  
            </p>

         

            <!-- CTA Button -->
            <table cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 40px;">
              <tr>
                <td align="center"
                    style="background-color:#0d1b2e; border-radius:3px;
                           border-left: 3px solid #c9a84c;">
                  <a href="mailto:contacto@edworld.mx"
                     style="display:inline-block; padding:13px 32px;
                            font-family:'Lato',Arial,sans-serif; font-size:12px;
                            font-weight:700; letter-spacing:2px; text-transform:uppercase;
                            color:#c9a84c; text-decoration:none;">
                    Contáctenos &rarr;
                  </a>
                </td>
              </tr>
            </table>

            <!-- Despedida -->
            <p style="font-family:'Lato',Arial,sans-serif; font-size:14px; color:#3a4556;
                       line-height:1.6; margin-bottom:4px;">
              Atentamente,
            </p>
            <p style="font-family:'Lato',Arial,sans-serif; font-size:15px; font-weight:700;
                       color:#0d1b2e; margin-bottom:2px;">
              Departamento de finanzas y administración
            </p>
            <p style="font-family:'Lato',Arial,sans-serif; font-size:13px; color:#c9a84c;
                       letter-spacing:1px; text-transform:uppercase; margin-bottom:2px;">
              
            </p>
            <p style="font-family:'Lato',Arial,sans-serif; font-size:13px; color:#7a9bb8;">
              Edworld Soluciones Administrativas Integrales S.C.
            </p>

          </td>
        </tr>

        <!-- ============================================================ -->
        <!-- FIRMA / FOOTER                                               -->
        <!-- ============================================================ -->
        <tr>
          <td style="background-color:#0d1b2e; padding: 0;">

            <!-- Franja divisora dorada -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="background-color:#c9a84c; height:1px; font-size:0; line-height:0;">&nbsp;</td>
              </tr>
            </table>

            <!-- Datos de contacto -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="padding: 32px 40px 28px 40px;">

                  <!-- Logo pequeño en firma -->
                  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:22px;">
                    <tr>
                      <td>
                        <img src="https://edworld.mx/logo.png"
                             alt="Edworld"
                             width="110"
                             style="display:block; max-width:110px;" />
                      </td>
                    </tr>
                  </table>

                  <!-- Tres columnas de datos -->
                  <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr valign="top">

                      <!-- Dirección -->
                      <td width="38%" style="padding-right:16px;">
                        <p style="font-family:'Lato',Arial,sans-serif; font-size:9px; letter-spacing:2px;
                                   text-transform:uppercase; color:#c9a84c; margin-bottom:8px; font-weight:700;">
                          &#9679; Dirección
                        </p>
                        <p style="font-family:'Lato',Arial,sans-serif; font-size:12px; color:#a8b9cc;
                                   line-height:1.7; margin:0;">
                          Pablo Villaseñor 128<br/>
                          Col. Arcos Vallarta<br/>
                          C.P. 44130<br/>
                          Guadalajara, Jalisco<br/>
                          México
                        </p>
                      </td>

                      <!-- Separador vertical -->
                      <td width="1" style="background-color:#1e3250; font-size:0;">&nbsp;</td>

                      <!-- Contacto -->
                      <td width="30%" style="padding: 0 20px;">
                        <p style="font-family:'Lato',Arial,sans-serif; font-size:9px; letter-spacing:2px;
                                   text-transform:uppercase; color:#c9a84c; margin-bottom:8px; font-weight:700;">
                          &#9679; Contacto
                        </p>
                        <p style="font-family:'Lato',Arial,sans-serif; font-size:12px; color:#a8b9cc;
                                   line-height:1.7; margin:0;">
                          <a href="tel:3315516454"
                             style="color:#a8b9cc; text-decoration:none; display:block;">
                            33 1551 6454
                          </a>
                          <a href="mailto:contacto@edworld.mx"
                             style="color:#c9a84c; text-decoration:none; display:block; margin-top:4px;">
                            contacto@edworld.mx
                          </a>
                        </p>
                      </td>

                      <!-- Separador vertical -->
                      <td width="1" style="background-color:#1e3250; font-size:0;">&nbsp;</td>

                      <!-- Servicios -->
                      <td style="padding-left:20px;">
                        <p style="font-family:'Lato',Arial,sans-serif; font-size:9px; letter-spacing:2px;
                                   text-transform:uppercase; color:#c9a84c; margin-bottom:8px; font-weight:700;">
                          &#9679; Servicios
                        </p>
                        <p style="font-family:'Lato',Arial,sans-serif; font-size:12px; color:#a8b9cc;
                                   line-height:1.7; margin:0;">
                          Asesoría Legal<br/>
                          Contabilidad<br/>
                          Administración<br/>
                          Consultoría<br/>
                          Optimización
                        </p>
                      </td>

                    </tr>
                  </table>

                </td>
              </tr>
            </table>

            <!-- Línea fina separadora -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="padding: 0 40px;">
                  <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                      <td style="background-color:#1e3250; height:1px; font-size:0; line-height:0;">&nbsp;</td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>

            <!-- Copyright + website -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="padding: 16px 40px 20px 40px;" align="center">
                  <p style="font-family:'Lato',Arial,sans-serif; font-size:11px; color:#3e5470;
                             margin:0; letter-spacing:0.5px;">
                    &copy; 2025 Edworld Soluciones Administrativas Integrales S.C. &nbsp;|&nbsp;
                    <a href="https://edworld.mx" style="color:#c9a84c; text-decoration:none;">
                      www.edworld.mx
                    </a>
                    &nbsp;|&nbsp; Todos los derechos reservados.
                  </p>
                  <p style="font-family:'Lato',Arial,sans-serif; font-size:10px; color:#2a3d52;
                             margin-top:6px; letter-spacing:0.3px;">
                    Este correo electrónico y sus adjuntos son confidenciales y están destinados
                    únicamente al destinatario indicado.
                  </p>
                </td>
              </tr>
            </table>

            <!-- Franja dorada inferior -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="background-color:#c9a84c; height:5px; font-size:0; line-height:0;">&nbsp;</td>
              </tr>
            </table>

          </td>
        </tr>

      </table>
      <!-- / CONTENEDOR DEL CORREO -->

    </td>
  </tr>
</table>
<!-- / WRAPPER PRINCIPAL -->

</body>
</html>

HTML;
}



function generaCorreoPicamue($mensaje)
{
  return <<<HTML
  <!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Correo Picamue</title>
  <link href="https://fonts.googleapis.com/css2?family=Exo+2:wght@300;400;600;700;800&family=Share+Tech+Mono&display=swap" rel="stylesheet"/>
</head>
<body style="margin:0; padding:0; background-color:#D9D0C0; font-family:'Exo 2', 'Segoe UI', sans-serif;">

  <!-- Fondo beige con cuadrícula sutil de plano arquitectónico -->
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
    style="background-color:#D9D0C0;
           background-image:
             linear-gradient(rgba(92,45,74,0.06) 1px, transparent 1px),
             linear-gradient(90deg, rgba(92,45,74,0.06) 1px, transparent 1px),
             linear-gradient(rgba(92,45,74,0.025) 1px, transparent 1px),
             linear-gradient(90deg, rgba(92,45,74,0.025) 1px, transparent 1px);
           background-size: 60px 60px, 60px 60px, 12px 12px, 12px 12px;
           padding:36px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="620" cellpadding="0" cellspacing="0" style="max-width:620px; width:100%;">

          <!-- ══════════════════════════════════
               TITLE BLOCK — Cartucho de plano
          ══════════════════════════════════ -->
          <tr>
            <td style="padding:0;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                style="border:1.5px solid #B8A898; border-radius:4px 4px 0 0; overflow:hidden;">

                <!-- Referencia del plano -->
                <tr>
                  <td style="background-color:#EDE5D8; padding:8px 20px; border-bottom:1px solid #C8BAA8;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                      <tr>
                        <td style="vertical-align:middle;">
                          <span style="font-family:'Share Tech Mono', monospace; font-size:9px; color:#A08878; letter-spacing:2px;">PLANO NÚM: PCM-COMM-2025 &nbsp;|&nbsp; REV: A &nbsp;|&nbsp; ESCALA: 1:1</span>
                        </td>
                        <td align="right" style="vertical-align:middle;">
                          <span style="font-family:'Share Tech Mono', monospace; font-size:9px; color:#B8A898; letter-spacing:1px;">GUADALAJARA, JAL.</span>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>

                <!-- Panel principal: logo + título + stat -->
                <tr>
                  <td style="background-color:#F5F0E8; padding:28px 28px 24px 28px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                      <tr>

                        <!-- Logo -->
                        <td style="vertical-align:middle; padding-right:24px; border-right:1.5px solid #C8BAA8; width:180px;">
                          <img src="https://picamue.com/rgb_picamue.jpg"
                               alt="Picamue"
                               width="170"
                               style="display:block; border:0; border-radius:3px;"/>
                        </td>

                        <!-- Título -->
                        <td style="vertical-align:middle; padding:0 24px; border-right:1.5px solid #C8BAA8;">
                          <p style="margin:0 0 4px 0; font-family:'Share Tech Mono', monospace; font-size:9px; color:#A08878; letter-spacing:3px; text-transform:uppercase;">Expertos en</p>
                          <p style="margin:0; font-family:'Exo 2', sans-serif; font-size:24px; font-weight:800; color:#3A2028; line-height:1.1; text-transform:uppercase; letter-spacing:0.5px;">SOLUCIONES<br/>DE <span style="color:#5C2D4A;">CONSTRUC<br/>CIÓN</span></p>
                        </td>

                        <!-- Stat -->
                        <td style="vertical-align:middle; padding-left:24px; text-align:center;">
                          <p style="margin:0; font-family:'Exo 2', sans-serif; font-size:36px; font-weight:800; color:#5C2D4A; line-height:1;">15<span style="font-size:20px;">+</span></p>
                          <p style="margin:2px 0 0 0; font-family:'Share Tech Mono', monospace; font-size:8px; color:#A08878; letter-spacing:2px; text-transform:uppercase; line-height:1.5;">AÑOS DE<br/>EXPERIENCIA</p>
                        </td>

                      </tr>
                    </table>
                  </td>
                </tr>

                <!-- Líneas de cota -->
                <tr>
                  <td style="background-color:#EDE5D8; padding:10px 28px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                      <tr>
                        <td style="width:20px; text-align:center; vertical-align:middle;">
                          <span style="font-family:'Share Tech Mono', monospace; font-size:8px; color:#C8BAA8;">◄</span>
                        </td>
                        <td style="height:1px; background-color:#C8BAA8;"></td>
                        <td style="padding:0 10px; text-align:center; vertical-align:middle; white-space:nowrap;">
                          <span style="font-family:'Share Tech Mono', monospace; font-size:8px; color:#A08878; letter-spacing:2px;">CONSTRUCCIÓN CIVIL · REMODELACIONES · DISEÑO ARQUITECTÓNICO · MANTENIMIENTO</span>
                        </td>
                        <td style="height:1px; background-color:#C8BAA8;"></td>
                        <td style="width:20px; text-align:center; vertical-align:middle;">
                          <span style="font-family:'Share Tech Mono', monospace; font-size:8px; color:#C8BAA8;">►</span>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>

              </table>
            </td>
          </tr>

          <!-- ══════════════════════════════════
               CUERPO — Panel crema
          ══════════════════════════════════ -->
          <tr>
            <td style="padding:0;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                style="background-color:#FAF6EE;
                       border-left:1.5px solid #B8A898;
                       border-right:1.5px solid #B8A898;">

                <tr>
                  <td style="padding:38px 44px 16px 44px;">

                    <!-- Etiqueta módulo -->
                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
                      <tr>
                        <td style="background-color:#EDE5D8; border-left:3px solid #5C2D4A; border-radius:0 2px 2px 0; padding:5px 14px;">
                          <span style="font-family:'Share Tech Mono', monospace; font-size:9px; color:#5C2D4A; letter-spacing:3px; text-transform:uppercase;">▣ MÓDULO: MENSAJE OFICIAL</span>
                        </td>
                      </tr>
                    </table>

                    <!-- Saludo -->
                    <p style="margin:0 0 4px 0; font-family:'Share Tech Mono', monospace; font-size:10px; color:#A08878; letter-spacing:3px; text-transform:uppercase;">Estimado //</p>
                    <p style="margin:0 0 22px 0; font-family:'Exo 2', sans-serif; font-size:24px; font-weight:700; color:#3A2028; line-height:1.2;">Cliente</p>

                    <!-- Divisor cota -->
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:22px;">
                      <tr>
                        <td style="width:16px; height:2px; background-color:#5C2D4A;"></td>
                        <td style="height:1px; background-color:#D9D0C0;"></td>
                        <td style="width:16px; height:2px; background-color:#A08878;"></td>
                      </tr>
                    </table>

                 
                    <p style="margin:0; font-size:14px; color:#4A3838; line-height:1.9;">
                      $mensaje
                    </p>

                  </td>
                </tr>

                <!-- Servicios — grid modular -->
                <tr>
                  <td style="padding:24px 44px 36px 44px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                      style="border:1.5px solid #C8BAA8; border-radius:3px; overflow:hidden;">

                      <tr>
                        <td width="50%" style="padding:14px 18px; border-right:1.5px solid #C8BAA8; border-bottom:1.5px solid #C8BAA8; vertical-align:top; background-color:#F5F0E8;">
                          <p style="margin:0 0 3px 0; font-family:'Share Tech Mono', monospace; font-size:8px; color:#5C2D4A; letter-spacing:2px;">S-01</p>
                          <p style="margin:0 0 2px 0; font-family:'Exo 2', sans-serif; font-size:13px; font-weight:700; color:#3A2028; text-transform:uppercase;">Construcción Civil</p>
                          <p style="margin:0; font-size:11px; color:#8A7868; line-height:1.5;">Edificaciones comerciales y residenciales con los más altos estándares</p>
                        </td>
                        <td width="50%" style="padding:14px 18px; border-bottom:1.5px solid #C8BAA8; vertical-align:top; background-color:#F5F0E8;">
                          <p style="margin:0 0 3px 0; font-family:'Share Tech Mono', monospace; font-size:8px; color:#8A5060; letter-spacing:2px;">S-02</p>
                          <p style="margin:0 0 2px 0; font-family:'Exo 2', sans-serif; font-size:13px; font-weight:700; color:#3A2028; text-transform:uppercase;">Remodelaciones</p>
                          <p style="margin:0; font-size:11px; color:#8A7868; line-height:1.5;">Transformamos espacios con soluciones innovadoras y funcionales</p>
                        </td>
                      </tr>

                      <tr>
                        <td width="50%" style="padding:14px 18px; border-right:1.5px solid #C8BAA8; vertical-align:top; background-color:#EDE5D8;">
                          <p style="margin:0 0 3px 0; font-family:'Share Tech Mono', monospace; font-size:8px; color:#5C2D4A; letter-spacing:2px;">S-03</p>
                          <p style="margin:0 0 2px 0; font-family:'Exo 2', sans-serif; font-size:13px; font-weight:700; color:#3A2028; text-transform:uppercase;">Diseño Arquitectónico</p>
                          <p style="margin:0; font-size:11px; color:#8A7868; line-height:1.5;">Proyectos ejecutivos que combinan innovación, forma y función</p>
                        </td>
                        <td width="50%" style="padding:14px 18px; vertical-align:top; background-color:#EDE5D8;">
                          <p style="margin:0 0 3px 0; font-family:'Share Tech Mono', monospace; font-size:8px; color:#8A5060; letter-spacing:2px;">S-04</p>
                          <p style="margin:0 0 2px 0; font-family:'Exo 2', sans-serif; font-size:13px; font-weight:700; color:#3A2028; text-transform:uppercase;">Mantenimiento de Obras</p>
                          <p style="margin:0; font-size:11px; color:#8A7868; line-height:1.5;">Planes preventivos y correctivos para garantizar durabilidad</p>
                        </td>
                      </tr>

                    </table>
                  </td>
                </tr>

              </table>
            </td>
          </tr>

          <!-- ══════════════════════════════════
               FIRMA — Cartucho inferior
          ══════════════════════════════════ -->
          <tr>
            <td style="padding:0;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                style="background-color:#EDE5D8;
                       border:1.5px solid #B8A898;
                       border-top:0;
                       border-radius:0 0 4px 4px;
                       overflow:hidden;">

                <!-- Línea borgoña superior firma -->
                <tr>
                  <td style="background: linear-gradient(90deg, #5C2D4A 0%, #8A5060 50%, #C8BAA8 100%); height:3px; font-size:0;">&nbsp;</td>
                </tr>

                <tr>
                  <td style="padding:28px 36px 26px 36px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                      <tr>

                        <!-- Datos remitente -->
                        <td style="vertical-align:top; padding-right:28px; border-right:1px solid #C8BAA8;">
                          <p style="margin:0 0 2px 0; font-family:'Share Tech Mono', monospace; font-size:8px; color:#A08878; letter-spacing:3px; text-transform:uppercase;">FIRMADO POR //</p>
                          <p style="margin:0 0 2px 0; font-family:'Exo 2', sans-serif; font-size:18px; font-weight:800; color:#3A2028; text-transform:uppercase; letter-spacing:0.5px;">Seguimiento y calidad</p>
                          <p style="margin:0 0 18px 0; font-family:'Exo 2', sans-serif; font-size:11px; color:#5C2D4A; text-transform:uppercase; letter-spacing:2px; font-weight:600;">Picamue</p>

                          <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:7px;">
                            <tr>
                              <td style="font-family:'Share Tech Mono', monospace; font-size:8px; color:#A08878; letter-spacing:1px; text-transform:uppercase; padding-right:10px; width:36px;">MAIL</td>
                              <td style="border-left:1px solid #C8BAA8; padding-left:10px;">
                                <a href="mailto:contacto@picamue.com" style="font-family:'Share Tech Mono', monospace; font-size:10px; color:#5C2D4A; text-decoration:none;">contacto@picamue.com</a>
                              </td>
                            </tr>
                          </table>
                          <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:7px;">
                            <tr>
                              <td style="font-family:'Share Tech Mono', monospace; font-size:8px; color:#A08878; letter-spacing:1px; text-transform:uppercase; padding-right:10px; width:36px;">TEL.</td>
                              <td style="border-left:1px solid #C8BAA8; padding-left:10px;">
                                <span style="font-family:'Share Tech Mono', monospace; font-size:10px; color:#4A3838;">33 23 24 03 33</span>
                              </td>
                            </tr>
                          </table>
                          <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:7px;">
                            <tr>
                              <td style="font-family:'Share Tech Mono', monospace; font-size:8px; color:#A08878; letter-spacing:1px; text-transform:uppercase; padding-right:10px; width:36px; vertical-align:top;">DIR.</td>
                              <td style="border-left:1px solid #C8BAA8; padding-left:10px;">
                                <span style="font-family:'Share Tech Mono', monospace; font-size:9px; color:#8A7868; line-height:1.6;">Pablo Villaseñor 128, Col. Arcos Vallarta<br/>Guadalajara, Jalisco, México</span>
                              </td>
                            </tr>
                          </table>
                          <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                              <td style="font-family:'Share Tech Mono', monospace; font-size:8px; color:#A08878; letter-spacing:1px; text-transform:uppercase; padding-right:10px; width:36px;">HOR.</td>
                              <td style="border-left:1px solid #C8BAA8; padding-left:10px;">
                                <span style="font-family:'Share Tech Mono', monospace; font-size:9px; color:#8A7868;">Lun–Vie 8:00–18:00 · Sáb 9:00–14:00</span>
                              </td>
                            </tr>
                          </table>
                        </td>

                        <!-- Sello PCM -->
                        <td style="vertical-align:middle; padding-left:28px; text-align:center; width:170px;">
                          <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 14px auto;">
                            <tr>
                              <td style="border:2px solid #5C2D4A; padding:2px;">
                                <table role="presentation" cellpadding="0" cellspacing="0">
                                  <tr>
                                    <td style="border:1px solid rgba(92,45,74,0.3); padding:12px 16px; text-align:center; background-color:#F5F0E8;">
                                      <p style="margin:0 0 3px 0; font-family:'Share Tech Mono', monospace; font-size:7px; color:#5C2D4A; letter-spacing:3px; text-transform:uppercase;">APROBADO</p>
                                      <p style="margin:0 0 3px 0; font-family:'Exo 2', sans-serif; font-size:22px; font-weight:800; color:#3A2028; line-height:1; letter-spacing:2px;">PCM</p>
                                      <p style="margin:0; font-family:'Share Tech Mono', monospace; font-size:6px; color:#A08878; letter-spacing:2px; text-transform:uppercase;">CONSTRUCCIONES</p>
                                    </td>
                                  </tr>
                                </table>
                              </td>
                            </tr>
                          </table>
                          <a href="http://picamue.com" style="display:block; font-family:'Share Tech Mono', monospace; font-size:10px; color:#5C2D4A; text-decoration:none; letter-spacing:1px;">picamue.com</a>
                        </td>

                      </tr>
                    </table>
                  </td>
                </tr>

                <!-- Barra de revisión final -->
                <tr>
                  <td style="background-color:#D9D0C0; padding:8px 36px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                      <tr>
                        <td style="width:8px; height:10px; background-color:#5C2D4A;"></td>
                        <td style="width:4px;"></td>
                        <td style="width:4px; height:10px; background-color:#8A5060;"></td>
                        <td style="width:4px;"></td>
                        <td style="width:4px; height:10px; background-color:#C8BAA8;"></td>
                        <td style="padding-left:12px; vertical-align:middle;">
                          <span style="font-family:'Share Tech Mono', monospace; font-size:8px; color:#A08878; letter-spacing:1px;">CLASIFICACIÓN: CONFIDENCIAL · DISTRIBUCIÓN RESTRINGIDA · © 2025 PICAMUE CONSTRUCCIONES · TODOS LOS DERECHOS RESERVADOS</span>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>

              </table>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>

</body>
</html>


HTML;
}



function generaCorreokulindra($mensaje)
{
  return <<<HTML
    <!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Correo Kulindra</title>
</head>
<body style="margin:0; padding:0; background-color:#1a1a1a; font-family:'Segoe UI', Arial, sans-serif;">

  <!-- WRAPPER -->
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#1a1a1a; padding: 30px 0;">
    <tr>
      <td align="center">

        <!-- EMAIL CONTAINER -->
        <table role="presentation" width="620" cellpadding="0" cellspacing="0" style="max-width:620px; width:100%; background-color:#ffffff; border-radius:4px; overflow:hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.5);">

          <!-- ===== CABECERA ===== -->
          <tr>
            <td style="background-color:#1E1E1E; padding: 0;">
              <!-- Barra de acento naranja superior -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="background-color:#C0561A; height:5px; font-size:0; line-height:0;">&nbsp;</td>
                </tr>
              </table>
              <!-- Logo y nombre -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="padding: 24px 36px 24px 36px; vertical-align:middle;">
                    <table role="presentation" cellpadding="0" cellspacing="0">
                      <tr>
                        <td style="vertical-align:middle; padding-right:16px;">
                          <img src="https://kulindra.com/logo.jpg"
                               alt="Kulindra Logo"
                               width="110"
                               style="display:block; border:0; border-radius:3px;" />
                        </td>
                        <td style="vertical-align:middle; border-left: 2px solid #C0561A; padding-left:18px;">
                          <p style="margin:0; font-size:20px; font-weight:700; color:#ffffff; letter-spacing:2px; text-transform:uppercase;">KULINDRA</p>
                          <p style="margin:4px 0 0 0; font-size:11px; color:#C0561A; letter-spacing:3px; text-transform:uppercase; font-weight:500;">Equipo &amp; Calzado Industrial</p>
                        </td>
                      </tr>
                    </table>
                  </td>
                  <td align="right" style="padding: 24px 36px; vertical-align:middle;">
                    <p style="margin:0; font-size:10px; color:#888888; text-transform:uppercase; letter-spacing:2px;">Manufactura y Comercialización</p>
                    <p style="margin:4px 0 0 0; font-size:10px; color:#555555; letter-spacing:1px;">Zacoalco de Torres, Jalisco</p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Línea separadora con gradiente -->
          <tr>
            <td style="background: linear-gradient(90deg, #C0561A 0%, #A8441A 40%, #3A3A3A 100%); height:3px; font-size:0; line-height:0;">&nbsp;</td>
          </tr>

          <!-- ===== CUERPO DEL MENSAJE ===== -->
          <tr>
            <td style="background-color:#f5f3f0; padding: 44px 40px 36px 40px;">

              <!-- Saludo -->
              <p style="margin:0 0 8px 0; font-size:13px; color:#C0561A; text-transform:uppercase; letter-spacing:2px; font-weight:600;">Estimado cliente</p>

              <!-- Línea decorativa -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
                <tr>
                  <td style="width:40px; height:3px; background-color:#C0561A;"></td>
                  <td style="height:3px; background-color:#e0ddd8;"></td>
                </tr>
              </table>

           
              <p style="margin:0 0 8px 0; font-size:15px; color:#333333; line-height:1.75;">
                $mensaje
              </p>


            </td>
          </tr>

          <!-- ===== FIRMA ===== -->
          <tr>
            <td style="background-color:#2A2A2A; padding:0;">
              <!-- Línea superior de acento -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="background: linear-gradient(90deg, #C0561A, #A8441A 30%, #333333 100%); height:2px; font-size:0;">&nbsp;</td>
                </tr>
              </table>

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <!-- Foto/logo firma -->
                  <td style="padding:28px 24px 28px 36px; vertical-align:top; width:100px;">
                    <img src="https://kulindra.com/logo.jpg"
                         alt="Kulindra"
                         width="80"
                         style="display:block; border:0; border-radius:3px; opacity:0.9;" />
                  </td>

                  <!-- Datos de contacto -->
                  <td style="padding:28px 36px 28px 0; vertical-align:top; border-left:2px solid #2d2d2d;">
                    <p style="margin:0 0 2px 14px; font-size:14px; font-weight:700; color:#ffffff; letter-spacing:1px;">Calidad y seguimiento</p>
                    <p style="margin:0 0 12px 14px; font-size:11px; color:#C0561A; text-transform:uppercase; letter-spacing:2px;">Kulindra</p>

                    <!-- Separador -->
                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 0 0 12px 14px;">
                      <tr>
                        <td style="width:28px; height:1px; background-color:#C0561A;"></td>
                        <td style="width:60px; height:1px; background-color:#3A3A3A;"></td>
                      </tr>
                    </table>

                    <!-- Ícono correo -->
                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:6px;">
                      <tr>
                        <td style="padding-left:14px; vertical-align:middle;">
                          <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                              <td style="width:18px; height:18px; background-color:#C0561A; border-radius:2px; text-align:center; vertical-align:middle;">
                                <span style="font-size:10px; color:#ffffff; line-height:18px;">✉</span>
                              </td>
                              <td style="padding-left:8px;">
                                <a href="mailto:contacto@kulindra.com"
                                   style="font-size:13px; color:#cccccc; text-decoration:none; letter-spacing:0.5px;">
                                  contacto@kulindra.com
                                </a>
                              </td>
                            </tr>
                          </table>
                        </td>
                      </tr>
                    </table>

                    <!-- Ícono teléfono -->
                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:6px;">
                      <tr>
                        <td style="padding-left:14px; vertical-align:middle;">
                          <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                              <td style="width:18px; height:18px; background-color:#3A3A3A; border-radius:2px; text-align:center; vertical-align:middle; border:1px solid #C0561A;">
                                <span style="font-size:10px; color:#C0561A; line-height:18px;">✆</span>
                              </td>
                              <td style="padding-left:8px;">
                                <span style="font-size:13px; color:#cccccc; letter-spacing:0.5px;">331 501 8193</span>
                              </td>
                            </tr>
                          </table>
                        </td>
                      </tr>
                    </table>

                    <!-- Ícono ubicación -->
                    <table role="presentation" cellpadding="0" cellspacing="0">
                      <tr>
                        <td style="padding-left:14px; vertical-align:top;">
                          <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                              <td style="width:18px; height:18px; background-color:#3A3A3A; border-radius:2px; text-align:center; vertical-align:middle; border:1px solid #4A4A4A;">
                                <span style="font-size:10px; color:#888888; line-height:18px;">⊙</span>
                              </td>
                              <td style="padding-left:8px; vertical-align:top;">
                                <span style="font-size:11px; color:#888888; line-height:1.5; letter-spacing:0.3px;">
                                  Lázaro Cárdenas #20 Int. A2, Col. Cruz Verde<br/>
                                  C.P. 45754, Zacoalco de Torres, Jalisco
                                </span>
                              </td>
                            </tr>
                          </table>
                        </td>
                      </tr>
                    </table>

                  </td>
                </tr>
              </table>

              <!-- Website banner -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="background-color:#161616; padding:12px 36px; text-align:center;">
                    <a href="https://kulindra.com" style="font-size:11px; color:#C0561A; text-decoration:none; letter-spacing:2px; text-transform:uppercase; font-weight:600;">www.kulindra.com</a>
                    <span style="color:#333333; margin:0 10px;">|</span>
                    <span style="font-size:10px; color:#555555; letter-spacing:1px;">Manufactura y Comercialización de Equipo Industrial</span>
                  </td>
                </tr>
              </table>

              <!-- Copyright -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="background-color:#A8441A; height:4px; font-size:0;">&nbsp;</td>
                </tr>
              </table>

            </td>
          </tr>

          <!-- DISCLAIMER LEGAL -->
          <tr>
            <td style="background-color:#1A1A1A; padding:16px 36px;">
              <p style="margin:0; font-size:10px; color:#444444; line-height:1.6; text-align:center;">
                Este correo electrónico y cualquier archivo adjunto son confidenciales y están destinados exclusivamente para el uso del destinatario indicado. Si usted no es el destinatario, le informamos que cualquier divulgación, copia o distribución de este mensaje está estrictamente prohibida. © 2024 Kulindra. Todos los derechos reservados.
              </p>
            </td>
          </tr>

        </table>
        <!-- FIN CONTAINER -->

      </td>
    </tr>
  </table>

</body>
</html>

HTML;
}

function generaCorreEnclenci($mensaje)
{
  return <<<HTML
   <!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Correo Enclenci</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700;900&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet"/>
</head>
<body style="margin:0; padding:0; background-color:#E8EDF2; font-family:'DM Sans', 'Segoe UI', sans-serif;">

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#E8EDF2; padding:36px 0;">
    <tr>
      <td align="center">

        <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px; width:100%;">

          <!-- ══════════════════════════════════════
               CABECERA — Layout asimétrico 2 col
          ══════════════════════════════════════ -->
          <tr>
            <td style="padding:0;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-radius:6px 6px 0 0; overflow:hidden;">
                <tr>

                  <!-- Columna izquierda: banda azul profundo -->
                  <td width="200" style="background-color:#0A2540; padding:40px 24px; vertical-align:top;">
                    <img src="https://enclenci.com/RGB_small_enclenci.jpg"
                         alt="Enclenci"
                         width="130"
                         style="display:block; border:0; margin-bottom:32px;"/>

                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:16px;">
                      <tr>
                        <td style="width:24px; height:2px; background-color:#3B9EDB;"></td>
                      </tr>
                    </table>

                    <p style="margin:0 0 6px 0; font-family:'Playfair Display', Georgia, serif; font-size:22px; font-weight:900; color:#ffffff; line-height:1.2; letter-spacing:-0.5px;">Consul&shy;toría<br/>de alto<br/>nivel.</p>
                    <p style="margin:20px 0 0 0; font-size:10px; color:#3B9EDB; letter-spacing:3px; text-transform:uppercase; font-weight:500;">Commercial<br/>Service</p>
                  </td>

                  <!-- Columna derecha -->
                  <td style="background-color:#F7F9FC; padding:0; vertical-align:top;">

                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                      <tr>
                        <td style="background: linear-gradient(135deg, #0A2540 0%, #1A4A7A 50%, #2471A3 100%); height:6px; font-size:0;">&nbsp;</td>
                      </tr>
                    </table>

                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                      <tr>
                        <td style="padding:32px 36px 28px 32px; vertical-align:top;">

                          <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
                            <tr>
                              <td style="background-color:#EAF2FB; border-left:3px solid #2471A3; padding:6px 14px;">
                                <span style="font-size:10px; color:#2471A3; letter-spacing:2px; text-transform:uppercase; font-weight:500;">Correspondencia Oficial · Enclenci</span>
                              </td>
                            </tr>
                          </table>

                          <p style="margin:0 0 6px 0; font-size:11px; color:#9EAEC0; letter-spacing:2px; text-transform:uppercase;">Estimado/a</p>
                          <p style="margin:0; font-family:'Playfair Display', Georgia, serif; font-size:26px; font-weight:700; color:#0A2540; line-height:1.2;">Cliente</p>

                          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0;">
                            <tr>
                              <td style="width:48px; height:2px; background-color:#2471A3;"></td>
                              <td style="height:1px; background-color:#D5E4F0;"></td>
                            </tr>
                          </table>

                        
                          <p style="margin:0; font-size:14px; color:#2C3E50; line-height:1.8;">
                            $mensaje  
                          </p>

                        </td>
                      </tr>
                    </table>

                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- ══════════════════════════════════════
               BANDA DE SERVICIOS
          ══════════════════════════════════════ -->
          <tr>
            <td style="background-color:#0A2540; padding:14px 32px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="vertical-align:middle;">
                    <span style="font-size:9px; color:#3B9EDB; letter-spacing:3px; text-transform:uppercase;">Servicios · </span>
                    <span style="font-size:9px; color:#4A6F8F; letter-spacing:2px;">Administración &nbsp;·&nbsp; Contabilidad &nbsp;·&nbsp; Finanzas &nbsp;·&nbsp; Auditoría &nbsp;·&nbsp; Impuestos &nbsp;·&nbsp; Comercio</span>
                  </td>
                  <td align="right" style="vertical-align:middle;">
                    <span style="font-size:9px; color:#4A6F8F; letter-spacing:1px;">Lun–Vie: 9:00–19:00 GMT-05</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- ══════════════════════════════════════
               FIRMA — Tarjeta de presentación
          ══════════════════════════════════════ -->
          <tr>
            <td style="background-color:#F0F4F8; padding:0; border-radius:0 0 6px 6px; overflow:hidden;">

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>

                  <!-- Info del remitente -->
                  <td style="padding:30px 0 30px 36px; vertical-align:middle;">
                    <p style="margin:0 0 3px 0; font-family:'Playfair Display', Georgia, serif; font-size:17px; font-weight:700; color:#0A2540;">Venas </p>
                    <p style="margin:0 0 16px 0; font-size:11px; color:#2471A3; text-transform:uppercase; letter-spacing:2px; font-weight:500;">Enclenci</p>

                    <table role="presentation" cellpadding="0" cellspacing="0">
                      <tr>
                        <td style="padding-right:20px; border-right:1px solid #C8D9E8;">
                          <p style="margin:0 0 2px 0; font-size:9px; color:#9EAEC0; text-transform:uppercase; letter-spacing:2px;">Correo</p>
                          <a href="/cdn-cgi/l/email-protection#3a5955544e5b594e557a5f5459565f54595314595557" style="font-size:12px; color:#0A2540; text-decoration:none; font-weight:500;"><span class="__cf_email__" data-cfemail="fe9d91908a9f9d8a91be9b909d929b909d97d09d9193">contacto@enclenci.com</span></a>
                        </td>
                        <td style="padding:0 20px; border-right:1px solid #C8D9E8;">
                          <p style="margin:0 0 2px 0; font-size:9px; color:#9EAEC0; text-transform:uppercase; letter-spacing:2px;">Teléfono</p>
                          <span style="font-size:12px; color:#0A2540; font-weight:500;">+52 (33) 2326 5581</span>
                        </td>
                        <td style="padding-left:20px;">
                          <p style="margin:0 0 2px 0; font-size:9px; color:#9EAEC0; text-transform:uppercase; letter-spacing:2px;">Web</p>
                          <a href="https://enclenci.com" style="font-size:12px; color:#2471A3; text-decoration:none; font-weight:500;">enclenci.com</a>
                        </td>
                      </tr>
                    </table>

                    <p style="margin:16px 0 0 0; font-size:11px; color:#9EAEC0; line-height:1.6;">
                      Lázaro Cárdenas #20 Int. A1, Col. Cruz Verde, C.P. 45754<br/>Zacoalco de Torres, Jalisco, México
                    </p>
                  </td>

                  <!-- Bloque monograma decorativo -->
                  <td width="80" style="background: linear-gradient(180deg, #0A2540 0%, #1A4A7A 100%); vertical-align:middle; text-align:center; padding:0 20px;">
                    <p style="margin:0; font-family:'Playfair Display', Georgia, serif; font-size:36px; font-weight:900; color:#3B9EDB; line-height:1; letter-spacing:-2px;">E</p>
                    <p style="margin:6px 0 0 0; font-size:7px; color:#4A6F8F; letter-spacing:3px; text-transform:uppercase;">ENCLENCI</p>
                  </td>

                </tr>
              </table>

              <!-- Barras inferiores dobles -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="background-color:#2471A3; height:3px; font-size:0;">&nbsp;</td>
                </tr>
                <tr>
                  <td style="background-color:#0A2540; height:2px; font-size:0;">&nbsp;</td>
                </tr>
              </table>

              <!-- Disclaimer -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="background-color:#EAF0F6; padding:14px 36px;">
                    <p style="margin:0; font-size:9px; color:#9EAEC0; line-height:1.7; text-align:center;">
                      Este correo electrónico y cualquier archivo adjunto son confidenciales y destinados exclusivamente al destinatario indicado. Cualquier divulgación, copia o distribución no autorizada está estrictamente prohibida. © 2024 Enclenci · Commercial Service. Todos los derechos reservados.
                    </p>
                  </td>
          


HTML;
}

function generaCorreoToki($mensaje)
{
  return <<<HTML
    <!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Correo TokiDoki-Mick</title>
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700;900&family=Bitter:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet"/>
</head>
<body style="margin:0; padding:0; background-color:#DDE8DC; font-family:'Nunito', 'Segoe UI', sans-serif;">

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#DDE8DC; padding:32px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="620" cellpadding="0" cellspacing="0" style="max-width:620px; width:100%;">

          <!-- ══════════════════════════════════
               CABECERA — Fondo orgánico con círculo
          ══════════════════════════════════ -->
          <tr>
            <td style="background: linear-gradient(150deg, #1B4332 0%, #2D6A4F 55%, #40916C 100%); border-radius:12px 12px 0 0; padding:0; overflow:hidden; position:relative;">

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <!-- Contenido izquierdo -->
                  <td style="padding:38px 0 38px 40px; vertical-align:middle;">

                    <!-- Tag eco -->
                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:18px;">
                      <tr>
                        <td style="background-color:rgba(255,255,255,0.12); border-radius:20px; padding:5px 14px;">
                          <span style="font-size:10px; color:#B7E4C7; letter-spacing:2.5px; text-transform:uppercase; font-weight:700;">♻ Produciendo a conciencia</span>
                        </td>
                      </tr>
                    </table>

                    <!-- Logo -->
                    <img src="https://tokidoki-mick.com/assets/img/logo/logo1.jpg"
                         alt="TokiDoki-Mick"
                         width="150"
                         style="display:block; border:0; border-radius:6px; margin-bottom:20px;"/>

                    <p style="margin:0 0 6px 0; font-family:'Bitter', Georgia, serif; font-size:13px; font-style:italic; color:#B7E4C7; letter-spacing:0.5px;">Reciclado de plástico · Soluciones sostenibles</p>

                    <!-- Línea decorativa -->
                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:18px;">
                      <tr>
                        <td style="width:36px; height:3px; background-color:#74C69D; border-radius:2px;"></td>
                        <td style="width:10px;"></td>
                        <td style="width:10px; height:3px; background-color:rgba(116,198,157,0.4); border-radius:2px;"></td>
                        <td style="width:6px;"></td>
                        <td style="width:6px; height:3px; background-color:rgba(116,198,157,0.2); border-radius:2px;"></td>
                      </tr>
                    </table>

                  </td>

                  <!-- Círculo decorativo derecho -->
                  <td width="180" style="vertical-align:middle; text-align:center; padding:30px 30px 30px 0;">
                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
                      <tr>
                        <td style="width:140px; height:140px; background-color:rgba(255,255,255,0.07); border-radius:50%; border:2px solid rgba(255,255,255,0.15); text-align:center; vertical-align:middle;">
                          <p style="margin:0; font-size:48px; line-height:1;">♻</p>
                          <p style="margin:6px 0 0 0; font-size:9px; color:#B7E4C7; letter-spacing:2px; text-transform:uppercase; font-weight:700;">Eco · Future</p>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>

              <!-- Ola inferior SVG simulada con degradado -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="background: linear-gradient(180deg, transparent 0%, rgba(0,0,0,0.15) 100%); height:8px; font-size:0;">&nbsp;</td>
                </tr>
              </table>

            </td>
          </tr>

          <!-- ══════════════════════════════════
               CUERPO — Tarjeta blanca elevada
          ══════════════════════════════════ -->
          <tr>
            <td style="background-color:#F5FAF5; padding:0;">

              <!-- Acento superior -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="width:60px; height:4px; background-color:#40916C;"></td>
                  <td style="height:4px; background-color:#D8EDDF;"></td>
                </tr>
              </table>

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="padding:36px 44px 32px 44px;">

                    <!-- Saludo -->
                    <p style="margin:0 0 4px 0; font-size:11px; color:#40916C; text-transform:uppercase; letter-spacing:3px; font-weight:700;">Estimado/a cliente</p>

                   
                    <p style="margin:0 0 0 0; font-size:14px; color:#3D5A47; line-height:1.85;">
                      $mensaje  
                    </p>

                  </td>
                </tr>
              </table>

              <!-- Bloque de valores — 3 pilares -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0; border-top:1px solid #D8EDDF;">
                <tr>
                  <td style="background-color:#EEF7EE; padding:18px 44px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                      <tr>
                        <td width="33%" style="text-align:center; padding:0 8px; border-right:1px solid #C8E6C9;">
                          <p style="margin:0 0 4px 0; font-size:18px;">🌿</p>
                          <p style="margin:0 0 2px 0; font-size:10px; font-weight:700; color:#1B4332; text-transform:uppercase; letter-spacing:1px;">Sostenibilidad</p>
                          <p style="margin:0; font-size:10px; color:#6B9B7A;">Compromiso real</p>
                        </td>
                        <td width="33%" style="text-align:center; padding:0 8px; border-right:1px solid #C8E6C9;">
                          <p style="margin:0 0 4px 0; font-size:18px;">♻️</p>
                          <p style="margin:0 0 2px 0; font-size:10px; font-weight:700; color:#1B4332; text-transform:uppercase; letter-spacing:1px;">Economía Circular</p>
                          <p style="margin:0; font-size:10px; color:#6B9B7A;">De desecho a producto</p>
                        </td>
                        <td width="33%" style="text-align:center; padding:0 8px;">
                          <p style="margin:0 0 4px 0; font-size:18px;">🏭</p>
                          <p style="margin:0 0 2px 0; font-size:10px; font-weight:700; color:#1B4332; text-transform:uppercase; letter-spacing:1px;">Innovación</p>
                          <p style="margin:0; font-size:10px; color:#6B9B7A;">Calidad certificada</p>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>

            </td>
          </tr>

          <!-- ══════════════════════════════════
               FIRMA — Badge horizontal
          ══════════════════════════════════ -->
          <tr>
            <td style="background-color:#1B4332; padding:0; border-radius:0 0 12px 12px; overflow:hidden;">

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="padding:28px 40px 24px 40px; vertical-align:top;">

                    <!-- Nombre y cargo -->
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
                      <tr>
                        <td style="vertical-align:middle;">
                          <p style="margin:0 0 3px 0; font-size:15px; font-weight:700; color:#ffffff; letter-spacing:0.5px;">Seguimiento TokiDoki</p>
                          <p style="margin:0; font-size:10px; color:#74C69D; text-transform:uppercase; letter-spacing:2.5px; font-weight:600;">TokiDoki-Mick</p>
                        </td>
                        <td align="right" style="vertical-align:middle;">
                          <!-- Badge eco -->
                          <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                              <td style="background-color:rgba(116,198,157,0.15); border:1px solid #40916C; border-radius:20px; padding:6px 16px; text-align:center;">
                                <span style="font-size:9px; color:#74C69D; letter-spacing:2px; text-transform:uppercase; font-weight:700;">♻ Eco Certified</span>
                              </td>
                            </tr>
                          </table>
                        </td>
                      </tr>
                    </table>

                    <!-- Línea separadora -->
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
                      <tr>
                        <td style="height:1px; background-color:rgba(116,198,157,0.2); font-size:0;">&nbsp;</td>
                      </tr>
                    </table>

                    <!-- Datos de contacto en fila -->
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                      <tr>

                        <td style="vertical-align:top; padding-right:24px;">
                          <p style="margin:0 0 3px 0; font-size:9px; color:#52B788; text-transform:uppercase; letter-spacing:2px;">✉ Correo</p>
                          <a href="mailto:contacto@tokidoki-mick.com" style="font-size:12px; color:#D8F3DC; text-decoration:none; font-weight:600;">contacto@tokidoki-mick.com</a>
                        </td>

                        <td style="vertical-align:top; padding:0 24px; border-left:1px solid rgba(116,198,157,0.2); border-right:1px solid rgba(116,198,157,0.2);">
                          <p style="margin:0 0 3px 0; font-size:9px; color:#52B788; text-transform:uppercase; letter-spacing:2px;">✆ Teléfono</p>
                          <span style="font-size:12px; color:#D8F3DC; font-weight:600;">+52 332 120 4912</span>
                        </td>

                        <td style="vertical-align:top; padding-left:24px;">
                          <p style="margin:0 0 3px 0; font-size:9px; color:#52B788; text-transform:uppercase; letter-spacing:2px;">⊙ Ubicación</p>
                          <span style="font-size:11px; color:#95D5B2; font-weight:400; line-height:1.5;">Zacoalco de Torres, Jalisco</span>
                        </td>

                      </tr>
                    </table>

                    <!-- Horario + web -->
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:18px;">
                      <tr>
                        <td style="vertical-align:middle;">
                          <span style="font-size:10px; color:#52B788;">◷</span>
                          <span style="font-size:10px; color:#6B9B7A; margin-left:6px;">Lun – Vie: 9:00 am – 6:00 pm (Guadalajara, Jal.)</span>
                        </td>
                        <td align="right" style="vertical-align:middle;">
                          <a href="https://tokidoki-mick.com" style="font-size:11px; color:#74C69D; text-decoration:none; font-weight:700; letter-spacing:1px;">tokidoki-mick.com</a>
                        </td>
                      </tr>
                    </table>

                  </td>
                </tr>
              </table>

              <!-- Barra final tricolor -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="background-color:#40916C; height:3px; font-size:0; width:40%;">&nbsp;</td>
                  <td style="background-color:#52B788; height:3px; font-size:0; width:30%;">&nbsp;</td>
                  <td style="background-color:#74C69D; height:3px; font-size:0; width:30%;">&nbsp;</td>
                </tr>
              </table>

              <!-- Disclaimer -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="background-color:#132E22; padding:12px 40px;">
                    <p style="margin:0; font-size:9px; color:#2D6A4F; line-height:1.7; text-align:center;">
                      Este correo es confidencial y está destinado exclusivamente al destinatario indicado. Cualquier distribución no autorizada está prohibida. © 2024 TokiDoki-Mick · Todos los derechos reservados. Comprometidos con el planeta 🌍
                    </p>
                  </td>
                </tr>
              </table>

            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>

</body>
</html>

HTML;
}

function generaCorreoRedTrade($mensaje)
{
  return <<<HTML
    <!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Correo RedTrade</title>
  <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800;900&family=Barlow:wght@300;400;500&display=swap" rel="stylesheet"/>
</head>
<body style="margin:0; padding:0; background-color:#0D0D0D; font-family:'Barlow', 'Segoe UI', sans-serif;">

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#111111; padding:28px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="620" cellpadding="0" cellspacing="0" style="max-width:620px; width:100%;">

          <!-- ══════════════════════════════════
               CABECERA — Full bleed rojo + negro
          ══════════════════════════════════ -->
          <tr>
            <td style="padding:0; border-radius:10px 10px 0 0; overflow:hidden;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">

                <!-- Franja top roja delgada -->
                <tr>
                  <td style="background-color:#E63946; height:4px; font-size:0;">&nbsp;</td>
                </tr>

                <!-- Cabecera principal -->
                <tr>
                  <td style="background-color:#0D0D0D; padding:32px 40px 28px 40px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                      <tr>
                        <!-- Logo -->
                        <td style="vertical-align:middle;">
                          <img src="https://redtrade.mx/logo_black.png"
                               alt="RedTrade"
                               width="140"
                               style="display:block; border:0; filter:invert(1) brightness(2);" />
                        </td>

                        <!-- Etiqueta marketplace -->
                        <td align="right" style="vertical-align:middle;">
                          <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                              <td style="border:1px solid #E63946; border-radius:3px; padding:5px 14px;">
                                <span style="font-family:'Barlow Condensed', sans-serif; font-size:11px; color:#E63946; letter-spacing:3px; text-transform:uppercase; font-weight:700;">🇲🇽 Marketplace Mexicano</span>
                              </td>
                            </tr>
                          </table>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>

                <!-- Hero band — diagonal cut simulado con degradado -->
                <tr>
                  <td style="background: linear-gradient(135deg, #E63946 0%, #C1121F 45%, #1A1A1A 46%, #0D0D0D 100%); padding:36px 40px 32px 40px;">
                    <p style="margin:0 0 6px 0; font-family:'Barlow Condensed', sans-serif; font-size:11px; color:rgba(255,255,255,0.5); letter-spacing:4px; text-transform:uppercase;">Comunicación oficial · RedTrade</p> 
                    <p style="margin:0; font-family:'Barlow Condensed', sans-serif; font-size:42px; font-weight:900; color:#ffffff; line-height:1; text-transform:uppercase; letter-spacing:-1px;">Hola,<br/><span style="color:#FFCDD2;"> estimado cliente</span></p>
                  </td>
                </tr>

              </table>
            </td>
          </tr>

          <!-- ══════════════════════════════════
               BARRA DE CATEGORÍAS
          ══════════════════════════════════ -->
          <tr>
            <td style="background-color:#1A1A1A; padding:11px 40px; border-top:1px solid #2A2A2A; border-bottom:1px solid #2A2A2A;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="text-align:center;">
                    <span style="font-family:'Barlow Condensed', sans-serif; font-size:10px; color:#555; letter-spacing:2px; text-transform:uppercase;">Tecnología</span>
                    <span style="color:#E63946; margin:0 10px; font-size:8px;">●</span>
                    <span style="font-family:'Barlow Condensed', sans-serif; font-size:10px; color:#555; letter-spacing:2px; text-transform:uppercase;">Moda</span>
                    <span style="color:#E63946; margin:0 10px; font-size:8px;">●</span>
                    <span style="font-family:'Barlow Condensed', sans-serif; font-size:10px; color:#555; letter-spacing:2px; text-transform:uppercase;">Hogar</span>
                    <span style="color:#E63946; margin:0 10px; font-size:8px;">●</span>
                    <span style="font-family:'Barlow Condensed', sans-serif; font-size:10px; color:#555; letter-spacing:2px; text-transform:uppercase;">Deportes</span>
                    <span style="color:#E63946; margin:0 10px; font-size:8px;">●</span>
                    <span style="font-family:'Barlow Condensed', sans-serif; font-size:10px; color:#555; letter-spacing:2px; text-transform:uppercase;">+50 categorías</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- ══════════════════════════════════
               CUERPO — Tarjeta oscura
          ══════════════════════════════════ -->
          <tr>
            <td style="background-color:#161616; padding:40px 40px 36px 40px;">

              <!-- Línea acento lateral izquierda -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td width="4" style="background-color:#E63946; border-radius:2px; vertical-align:top;"></td>
                  <td style="padding-left:20px; vertical-align:top; color: #d3d3d3;">

                  
                    <p style="margin:0; font-size:15px; color:#CCCCCC; line-height:1.85; font-weight:300;">
                      $mensaje
                    </p>

                  </td>
                </tr>
              </table>

              <!-- Stats 3 columnas -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:32px; border-top:1px solid #2A2A2A; border-bottom:1px solid #2A2A2A;">
                <tr>
                  <td width="33%" style="text-align:center; padding:20px 0; border-right:1px solid #2A2A2A;">
                    <p style="margin:0; font-family:'Barlow Condensed', sans-serif; font-size:30px; font-weight:900; color:#E63946; line-height:1;">50K+</p>
                    <p style="margin:4px 0 0 0; font-size:10px; color:#555; text-transform:uppercase; letter-spacing:2px;">Clientes activos</p>
                  </td>
                  <td width="33%" style="text-align:center; padding:20px 0; border-right:1px solid #2A2A2A;">
                    <p style="margin:0; font-family:'Barlow Condensed', sans-serif; font-size:30px; font-weight:900; color:#E63946; line-height:1;">12K+</p>
                    <p style="margin:4px 0 0 0; font-size:10px; color:#555; text-transform:uppercase; letter-spacing:2px;">Productos</p>
                  </td>
                  <td width="33%" style="text-align:center; padding:20px 0;">
                    <p style="margin:0; font-family:'Barlow Condensed', sans-serif; font-size:30px; font-weight:900; color:#E63946; line-height:1;">98%</p>
                    <p style="margin:4px 0 0 0; font-size:10px; color:#555; text-transform:uppercase; letter-spacing:2px;">Satisfacción</p>
                  </td>
                </tr>
              </table>

            </td>
          </tr>

          <!-- ══════════════════════════════════
               FIRMA — Panel oscuro con acento
          ══════════════════════════════════ -->
          <tr>
            <td style="background-color:#0D0D0D; padding:0; border-radius:0 0 10px 10px; overflow:hidden; border-top:1px solid #E63946;">

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>

                  <!-- Datos remitente -->
                  <td style="padding:28px 0 26px 40px; vertical-align:middle;">

                    <p style="margin:0 0 2px 0; font-family:'Barlow Condensed', sans-serif; font-size:20px; font-weight:800; color:#ffffff; text-transform:uppercase; letter-spacing:1px;">Departamento de relaciones</p>
                    <p style="margin:0 0 18px 0; font-size:11px; color:#E63946; text-transform:uppercase; letter-spacing:3px; font-weight:500;">RedTrade</p>

                    <!-- Contacto en píldoras -->
                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:10px;">
                      <tr>
                        <td style="padding-right:10px;">
                          <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                              <td style="background-color:#1A1A1A; border:1px solid #2A2A2A; border-radius:3px; padding:6px 12px;">
                                <span style="font-size:10px; color:#888; margin-right:6px;">✉</span>
                                <a href="mailto:contacto@redtrade.mx" style="font-size:11px; color:#CCCCCC; text-decoration:none; font-weight:500;">contacto@redtrade.mx</a>
                              </td>
                            </tr>
                          </table>
                        </td>
                        <td>
                          <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                              <td style="background-color:#1A1A1A; border:1px solid #2A2A2A; border-radius:3px; padding:6px 12px;">
                                <span style="font-size:10px; color:#888; margin-right:6px;">✆</span>
                                <span style="font-size:11px; color:#CCCCCC; font-weight:500;">332 513 2734</span>
                              </td>
                            </tr>
                          </table>
                        </td>
                      </tr>
                    </table>

                    <table role="presentation" cellpadding="0" cellspacing="0">
                      <tr>
                        <td style="background-color:#1A1A1A; border:1px solid #2A2A2A; border-radius:3px; padding:6px 12px;">
                          <span style="font-size:10px; color:#888; margin-right:6px;">⊙</span>
                          <span style="font-size:11px; color:#666;">Pablo Villaseñor 128, Arcos Vallarta, Guadalajara, Jal.</span>
                        </td>
                      </tr>
                    </table>

                  </td>

                  <!-- Web + soporte 24/7 -->
                  <td width="150" align="center" style="padding:28px 30px 26px 20px; vertical-align:middle; border-left:1px solid #1E1E1E;">
                    <p style="margin:0 0 4px 0; font-family:'Barlow Condensed', sans-serif; font-size:11px; color:#E63946; letter-spacing:3px; text-transform:uppercase;">Soporte</p>
                    <p style="margin:0 0 14px 0; font-family:'Barlow Condensed', sans-serif; font-size:22px; font-weight:900; color:#ffffff; line-height:1;">24/7</p>
                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 14px auto;">
                      <tr>
                        <td style="background-color:#E63946; border-radius:3px; padding:7px 16px; text-align:center;">
                          <a href="https://redtrade.mx" style="font-family:'Barlow Condensed', sans-serif; font-size:12px; color:#ffffff; text-decoration:none; font-weight:700; letter-spacing:2px; text-transform:uppercase;">redtrade.mx</a>
                        </td>
                      </tr>
                    </table>
                    <p style="margin:0; font-size:9px; color:#3A3A3A; text-transform:uppercase; letter-spacing:1px;">Lun–Vie 9–20h<br/>Sáb 10–16h</p>
                  </td>

                </tr>
              </table>

              <!-- Barra final roja -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="background: linear-gradient(90deg, #E63946 0%, #C1121F 60%, #0D0D0D 100%); height:3px; font-size:0;">&nbsp;</td>
                </tr>
              </table>

              <!-- Disclaimer -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="background-color:#080808; padding:12px 40px;">
                    <p style="margin:0; font-size:9px; color:#2A2A2A; line-height:1.7; text-align:center;">
                      Este correo electrónico es confidencial y está destinado exclusivamente al destinatario. Cualquier distribución o reproducción no autorizada está prohibida. © 2025 RedTrade · RED TRADE · Todos los derechos reservados · Guadalajara, México.
                    </p>
                  </td>
                </tr>
              </table>

            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>

</body>
</html>

HTML;
}

function marcaVivaContenidoNegro($mensaje)
{
  $mensaje = (string) $mensaje;
  if ($mensaje === '' || !class_exists('DOMDocument')) {
    return $mensaje;
  }

  $dom = new DOMDocument('1.0', 'UTF-8');
  $erroresPrevios = libxml_use_internal_errors(true);

  $html = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head><body>'
    . '<div id="mv-contenido-dinamico">' . $mensaje . '</div>'
    . '</body></html>';
  $cargado = $dom->loadHTML($html);
  libxml_clear_errors();
  libxml_use_internal_errors($erroresPrevios);

  if (!$cargado) {
    return $mensaje;
  }

  $xpath = new DOMXPath($dom);
  $coincidencias = $xpath->query('//*[@id="mv-contenido-dinamico"]');
  $contenedor = $coincidencias->item(0);
  if (!$contenedor) {
    return $mensaje;
  }

  $elementos = $xpath->query('.//*', $contenedor);
  foreach ($elementos as $elemento) {
    $etiqueta = strtolower($elemento->tagName);
    // Solo modificar elementos de contenido, no estilos ni recursos.
    if (in_array($etiqueta, array('style', 'script', 'img', 'br', 'hr', 'meta', 'link', 'source'), true)) {
      continue;
    }

    $estilo = $elemento->getAttribute('style');
    // Quitar color anterior, incluso color:white !important, sin alterar background-color.
    $estilo = preg_replace('/(^|;)\s*color\s*:[^;]*/i', '$1', $estilo);
    $estilo = trim($estilo, " \t\r\n;");
    $elemento->setAttribute('style', ($estilo !== '' ? $estilo . '; ' : '') . 'color:#000000 !important;');

    // Compatibilidad con etiquetas antiguas: <font color="white">.
    if ($elemento->hasAttribute('color')) {
      $elemento->setAttribute('color', '#000000');
    }
  }

  $salida = '';
  foreach ($contenedor->childNodes as $nodo) {
    $salida .= $dom->saveHTML($nodo);
  }
  return $salida;
}

function generaCorreMarcaViva($mensaje)

{
  $mensaje = marcaVivaContenidoNegro($mensaje);

  return <<<HTML

    <!DOCTYPE html>

<html lang="es">

<head>

  <meta charset="UTF-8"/>

  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>

  <title>Correo Marca Viva</title>

  <!-- Respaldo CSS: algunos clientes de correo reescriben estilos del HTML recibido. -->
  <style type="text/css">
    #mv-mensaje, #mv-mensaje * { color: #000000 !important; }
  </style>

  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Inter:wght@300;400;500&display=swap" rel="stylesheet"/>

</head>

<body style="margin:0; padding:0; background-color:#F0EDE8; font-family:'Inter', 'Segoe UI', sans-serif;">



  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F0EDE8; padding:32px 0;">

    <tr>

      <td align="center">

        <table role="presentation" width="620" cellpadding="0" cellspacing="0" style="max-width:620px; width:100%;">



          <!-- ══════════════════════════════════

               CABECERA — Bloque de impacto total

          ══════════════════════════════════ -->

          <tr>

            <td style="padding:0; border-radius:10px 10px 0 0; overflow:hidden;">

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">



                <!-- Franja superior bicolor -->

                <tr>

                  <td width="55%" style="background-color:#FF3C5F; height:6px; font-size:0;">&nbsp;</td>

                  <td width="45%" style="background-color:#FF8C42; height:6px; font-size:0;">&nbsp;</td>

                </tr>



                <!-- Bloque principal negro -->

                <tr>

                  <td style="background-color:#0F0F0F; padding:36px 40px 0 40px;">

                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">

                      <tr>

                        <!-- Logo -->

                        <td style="vertical-align:top; padding-bottom:28px;">

                          <img src="https://sistema14.com/app//img/empresas/Imagen35439.jpg"

                               alt="Marca Viva"

                               width="120"

                               style="display:block; border:0; border-radius:4px;"/>

                        </td>

                        <!-- Pill tag -->

                        <td align="right" style="vertical-align:top; padding-bottom:28px;">

                          <table role="presentation" cellpadding="0" cellspacing="0">

                            <tr>

                              <td style="background: linear-gradient(90deg, #FF3C5F, #FF8C42); border-radius:20px; padding:6px 16px;">

                                <span style="font-family:'Syne', sans-serif; font-size:10px; color:#fff; letter-spacing:2px; text-transform:uppercase; font-weight:700;">Agencia Creativa · Gdl.</span>

                              </td>

                            </tr>

                          </table>

                        </td>

                      </tr>

                    </table>



                    <!-- Tipografía expresiva gigante -->

                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">

                      <tr>

                        <td style="padding-bottom:0;">

                          <p style="margin:0; font-family:'Syne', sans-serif; font-size:58px; font-weight:800; color:#ffffff; line-height:0.92; letter-spacing:-2px; text-transform:uppercase;">PUBLI<span style="color:#FF3C5F;">CI</span>DAD</p>

                          <p style="margin:0; font-family:'Syne', sans-serif; font-size:58px; font-weight:800; line-height:0.92; letter-spacing:-2px; text-transform:uppercase;">

                            <span style="color:#FF8C42;">QUE</span>

                            <span style="color:#333333;"> IMPACTA</span>

                          </p>

                        </td>

                      </tr>

                    </table>



                    <!-- Sub línea -->

                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:18px; margin-bottom:0;">

                      <tr>

                        <td style="border-left:3px solid #FF3C5F; padding-left:14px; padding-bottom:36px;">

                          <p style="margin:0; font-size:12px; color:#888; line-height:1.7; font-weight:300; letter-spacing:0.3px;">Creatividad · Estrategia · Tecnología · +10 años transformando marcas</p>

                        </td>

                      </tr>

                    </table>



                  </td>

                </tr>



                <!-- Franja de transición -->

                <tr>

                  <td style="background: linear-gradient(90deg, #FF3C5F 0%, #FF8C42 50%, #FFD166 100%); height:4px; font-size:0;">&nbsp;</td>

                </tr>



              </table>

            </td>

          </tr>



          <!-- ══════════════════════════════════

               CUERPO — Fondo crema con acento

          ══════════════════════════════════ -->

          <tr>

            <td bgcolor="#FFFFFF" style="background-color:#FFFFFF; padding:0;">



              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">

                <tr>

                  <td bgcolor="#FFFFFF" style="padding:38px 44px 16px 44px; background-color:#FFFFFF; color:#000000 !important;">



                    <!-- Saludo -->

                    <p style="margin:0 0 4px 0; font-size:10px; color:#FF3C5F; text-transform:uppercase; letter-spacing:3px; font-weight:600;">Estimado</p>

                    <p style="margin:0 0 26px 0; font-family:'Syne', sans-serif; font-size:26px; font-weight:700; color:#0F0F0F; line-height:1.2;">Cliente</p>





                    <div id="mv-mensaje" style="margin:0; padding:0; font-size:14px; line-height:1.9; font-weight:400; background-color:#FFFFFF; color:#000000 !important;">

                      $mensaje

                    </div>



                  </td>

                </tr>

              </table>



              <!-- Servicios — 4 chips en 2x2 -->

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:20px 44px 36px 44px;">

                <tr>

                  <td style="padding:0 8px 8px 0;" width="50%">

                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">

                      <tr>

                        <td style="background-color:#FFF0F3; border-left:3px solid #FF3C5F; padding:10px 14px; border-radius:0 4px 4px 0;">

                          <p style="margin:0; font-size:10px; font-weight:700; color:#FF3C5F; text-transform:uppercase; letter-spacing:1.5px;">Branding Creativo</p>

                          <p style="margin:2px 0 0 0; font-size:11px; color:#888;">Identidad visual memorable</p>

                        </td>

                      </tr>

                    </table>

                  </td>

                  <td style="padding:0 0 8px 8px;" width="50%">

                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">

                      <tr>

                        <td style="background-color:#FFF5EF; border-left:3px solid #FF8C42; padding:10px 14px; border-radius:0 4px 4px 0;">

                          <p style="margin:0; font-size:10px; font-weight:700; color:#FF8C42; text-transform:uppercase; letter-spacing:1.5px;">Marketing Digital</p>

                          <p style="margin:2px 0 0 0; font-size:11px; color:#888;">Redes sociales y Google Ads</p>

                        </td>

                      </tr>

                    </table>

                  </td>

                </tr>

                <tr>

                  <td style="padding:0 8px 0 0;" width="50%">

                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">

                      <tr>

                        <td style="background-color:#FFFBEF; border-left:3px solid #FFD166; padding:10px 14px; border-radius:0 4px 4px 0;">

                          <p style="margin:0; font-size:10px; font-weight:700; color:#C9A000; text-transform:uppercase; letter-spacing:1.5px;">Producción Audiovisual</p>

                          <p style="margin:2px 0 0 0; font-size:11px; color:#888;">Video, TV y redes sociales</p>

                        </td>

                      </tr>

                    </table>

                  </td>

                  <td style="padding:0 0 0 8px;" width="50%">

                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">

                      <tr>

                        <td style="background-color:#F3F3FF; border-left:3px solid #7B5EA7; padding:10px 14px; border-radius:0 4px 4px 0;">

                          <p style="margin:0; font-size:10px; font-weight:700; color:#7B5EA7; text-transform:uppercase; letter-spacing:1.5px;">Publicidad Exterior</p>

                          <p style="margin:2px 0 0 0; font-size:11px; color:#888;">Vallas y espectaculares</p>

                        </td>

                      </tr>

                    </table>

                  </td>

                </tr>

              </table>



            </td>

          </tr>



          <!-- ══════════════════════════════════

               FIRMA — Split blanco/negro

          ══════════════════════════════════ -->

          <tr>

            <td style="padding:0; border-radius:0 0 10px 10px; overflow:hidden;">

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">

                <tr>



                  <!-- Mitad izquierda blanca -->

                  <td width="55%" style="background-color:#ffffff; padding:28px 0 28px 40px; vertical-align:middle; border-top:3px solid #F0EDE8;">

                    <p style="margin:0 0 2px 0; font-family:'Syne', sans-serif; font-size:17px; font-weight:800; color:#0F0F0F; text-transform:uppercase; letter-spacing:0.5px;">Bussines departament</p>

                    <p style="margin:0 0 18px 0; font-size:10px; color:#FF3C5F; text-transform:uppercase; letter-spacing:3px; font-weight:600;"> Marca Viva</p>



                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:8px;">

                      <tr>

                        <td style="vertical-align:middle; padding-right:8px;">

                          <span style="font-size:12px; color:#FF3C5F;">✉</span>

                        </td>

                        <td>

                          <a href="mailto:contacto@marcaviva.mx" style="font-size:12px; color:#0F0F0F; text-decoration:none; font-weight:500;">contacto@marcaviva.mx</a>

                        </td>

                      </tr>

                    </table>

                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:8px;">

                      <tr>

                        <td style="vertical-align:middle; padding-right:8px;">

                          <span style="font-size:12px; color:#FF8C42;">✆</span>

                        </td>

                        <td>

                          <span style="font-size:12px; color:#0F0F0F; font-weight:500;">33 1904 4716</span>

                        </td>

                      </tr>

                    </table>

                    <table role="presentation" cellpadding="0" cellspacing="0">

                      <tr>

                        <td style="vertical-align:middle; padding-right:8px;">

                          <span style="font-size:12px; color:#BBB;">⊙</span>

                        </td>

                        <td>

                          <span style="font-size:11px; color:#999;">Pablo Villaseñor 128, Arcos Vallarta, Gdl. Jal.</span>

                        </td>

                      </tr>

                    </table>

                  </td>



                  <!-- Mitad derecha negra con URL -->

                  <td width="45%" style="background-color:#0F0F0F; padding:28px 30px; vertical-align:middle; text-align:center;">

                    <p style="margin:0 0 8px 0; font-family:'Syne', sans-serif; font-size:11px; color:#555; letter-spacing:3px; text-transform:uppercase;">Visítanos</p>

                    <a href="https://marcaviva.mx" style="font-family:'Syne', sans-serif; font-size:18px; font-weight:800; color:#ffffff; text-decoration:none; text-transform:uppercase; letter-spacing:-0.5px;">marcaviva.mx</a>



                    <!-- Mini separador -->

                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:16px auto;">

                      <tr>

                        <td style="width:20px; height:2px; background-color:#FF3C5F;"></td>

                        <td style="width:8px; height:2px; background-color:#FF8C42; margin-left:4px;"></td>

                        <td style="width:4px; height:2px; background-color:#FFD166; margin-left:4px;"></td>

                      </tr>

                    </table>



                    <p style="margin:0; font-size:9px; color:#333; letter-spacing:2px; text-transform:uppercase; line-height:1.8;">Creatividad<br/>Estrategia<br/>Impacto</p>

                  </td>



                </tr>

              </table>



              <!-- Barra gradiente final -->

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">

                <tr>

                  <td style="background: linear-gradient(90deg, #FF3C5F 0%, #FF8C42 50%, #FFD166 100%); height:4px; font-size:0;">&nbsp;</td>

                </tr>

              </table>



              <!-- Disclaimer -->

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">

                <tr>

                  <td style="background-color:#F0EDE8; padding:12px 40px;">

                    <p style="margin:0; font-size:9px; color:#BBBBBB; line-height:1.7; text-align:center;">

                      Este correo es confidencial y destinado exclusivamente al destinatario indicado. Queda prohibida su distribución no autorizada. © 2025 Marca Viva · Agencia Creativa · Guadalajara, México. Todos los derechos reservados.

                    </p>

                  </td>

                </tr>

              </table>



            </td>

          </tr>



        </table>

      </td>

    </tr>

  </table>



</body>

</html>



HTML;
}



function generaCorreoZ4($mensaje)
{
  return <<<HTML
    <!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Correo Agropecuaria Z4</title>
  <link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=Source+Sans+3:wght@300;400;600&display=swap" rel="stylesheet"/>
</head>
<body style="margin:0; padding:0; background-color:#C8B89A; font-family:'Source Sans 3', 'Segoe UI', sans-serif;">

  <!-- Textura kraft simulada con patrón punteado -->
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
    style="background-color:#C8B89A;
           background-image: radial-gradient(circle, rgba(0,0,0,0.06) 1px, transparent 1px);
           background-size: 6px 6px;
           padding:36px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%;">

          <!-- ══════════════════════════════════
               SELLO SUPERIOR — Estilo etiqueta
          ══════════════════════════════════ -->
          <tr>
            <td style="text-align:center; padding:0 0 0 0;">
              <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
                <tr>
                  <td style="background-color:#3A5A2A; border-radius:50px; padding:7px 28px;">
                    <span style="font-family:'Source Sans 3', sans-serif; font-size:10px; color:#C8E6B0; letter-spacing:4px; text-transform:uppercase; font-weight:600;">✦ Jalisco, México · Desde 2010 ✦</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- ══════════════════════════════════
               CABECERA — Etiqueta artesanal
          ══════════════════════════════════ -->
          <tr>
            <td style="padding:12px 0 0 0;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                style="background-color:#F5EDD8;
                       border:3px solid #7A5C2E;
                       border-radius:6px 6px 0 0;
                       overflow:hidden;">

                <!-- Franja vegetal superior -->
                <tr>
                  <td style="background: repeating-linear-gradient(90deg, #3A5A2A 0px, #3A5A2A 14px, #4A7A38 14px, #4A7A38 28px, #3A5A2A 28px); height:8px; font-size:0;">&nbsp;</td>
                </tr>

                <!-- Logo + nombre centrado -->
                <tr>
                  <td style="padding:36px 40px 20px 40px; text-align:center;">
                    <img src="https://agropecuaria-z4.com/logo.png"
                         alt="Agropecuaria Z4"
                         width="110"
                         style="display:block; margin:0 auto 18px auto; border:0;"/>

                    <!-- Línea decorativa artesanal -->
                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 14px auto;">
                      <tr>
                        <td style="width:40px; height:1px; background-color:#7A5C2E;"></td>
                        <td style="width:10px; text-align:center; padding:0 6px;">
                          <span style="font-size:12px; color:#7A5C2E; line-height:1;">❧</span>
                        </td>
                        <td style="width:40px; height:1px; background-color:#7A5C2E;"></td>
                      </tr>
                    </table>

                    <p style="margin:0; font-family:'Libre Baskerville', Georgia, serif; font-size:28px; font-weight:700; color:#2A3E1A; letter-spacing:1px; text-transform:uppercase; line-height:1.1;">Agropecuaria Z4</p>
                    <p style="margin:8px 0 0 0; font-family:'Libre Baskerville', Georgia, serif; font-size:13px; font-style:italic; color:#7A5C2E; letter-spacing:0.5px;">Del campo a tu mesa con compromiso y calidad</p>

                    <!-- Línea decorativa inferior -->
                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:16px auto 0 auto;">
                      <tr>
                        <td style="width:60px; height:1px; background-color:#B8A070;"></td>
                        <td style="width:10px; text-align:center; padding:0 6px;">
                          <span style="font-size:10px; color:#B8A070;">✦</span>
                        </td>
                        <td style="width:60px; height:1px; background-color:#B8A070;"></td>
                      </tr>
                    </table>
                  </td>
                </tr>

                <!-- Franja vegetal inferior cabecera -->
                <tr>
                  <td style="background: repeating-linear-gradient(90deg, #5A8040 0px, #5A8040 14px, #3A5A2A 14px, #3A5A2A 28px); height:5px; font-size:0;">&nbsp;</td>
                </tr>

              </table>
            </td>
          </tr>

          <!-- ══════════════════════════════════
               CUERPO — Papel crema con borde
          ══════════════════════════════════ -->
          <tr>
            <td style="padding:0;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                style="background-color:#FBF5E6;
                       border-left:3px solid #7A5C2E;
                       border-right:3px solid #7A5C2E;">
                <tr>
                  <td style="padding:38px 48px 32px 48px;">

                    <!-- Saludo con ornamento -->
                    <p style="margin:0 0 4px 0; font-size:10px; color:#A07840; text-transform:uppercase; letter-spacing:4px; font-weight:600;">Estimado/a</p>
                    <p style="margin:0 0 24px 0; font-family:'Libre Baskerville', Georgia, serif; font-size:22px; font-weight:700; color:#2A3E1A;">Cliente,</p>

                    <!-- Separador ornamental -->
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
                      <tr>
                        <td style="height:1px; background-color:#D4B880;"></td>
                        <td style="width:24px; text-align:center; padding:0 8px;">
                          <span style="font-size:14px; color:#D4B880;">❦</span>
                        </td>
                        <td style="height:1px; background-color:#D4B880;"></td>
                      </tr>
                    </table>

                 
                    <p style="margin:0; font-size:14px; color:#3D3020; line-height:1.9;">
                      $mensaje
                    </p>

                  </td>
                </tr>

                <!-- Badges de productos — fila de sellos -->
                <tr>
                  <td style="padding:0 48px 36px 48px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                      style="background-color:#F0E8CC; border:1px dashed #B8A070; border-radius:4px; padding:18px 20px;">
                      <tr>
                        <td width="25%" style="text-align:center; padding:8px 4px; border-right:1px dashed #C8B070;">
                          <p style="margin:0 0 4px 0; font-size:22px; line-height:1;">🍓</p>
                          <p style="margin:0; font-size:9px; color:#5A3E1A; text-transform:uppercase; letter-spacing:1.5px; font-weight:600;">Berries<br/>Frescas</p>
                        </td>
                        <td width="25%" style="text-align:center; padding:8px 4px; border-right:1px dashed #C8B070;">
                          <p style="margin:0 0 4px 0; font-size:22px; line-height:1;">🌱</p>
                          <p style="margin:0; font-size:9px; color:#5A3E1A; text-transform:uppercase; letter-spacing:1.5px; font-weight:600;">Semillas<br/>Certificadas</p>
                        </td>
                        <td width="25%" style="text-align:center; padding:8px 4px; border-right:1px dashed #C8B070;">
                          <p style="margin:0 0 4px 0; font-size:22px; line-height:1;">🚜</p>
                          <p style="margin:0; font-size:9px; color:#5A3E1A; text-transform:uppercase; letter-spacing:1.5px; font-weight:600;">Maquinaria<br/>Agrícola</p>
                        </td>
                        <td width="25%" style="text-align:center; padding:8px 4px;">
                          <p style="margin:0 0 4px 0; font-size:22px; line-height:1;">👨‍🌾</p>
                          <p style="margin:0; font-size:9px; color:#5A3E1A; text-transform:uppercase; letter-spacing:1.5px; font-weight:600;">Asesoría<br/>Técnica</p>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>

              </table>
            </td>
          </tr>

          <!-- ══════════════════════════════════
               FIRMA — Pergamino oscuro
          ══════════════════════════════════ -->
          <tr>
            <td style="padding:0;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                style="background-color:#2A3E1A;
                       border:3px solid #7A5C2E;
                       border-top:0;
                       border-radius:0 0 6px 6px;
                       overflow:hidden;">

                <!-- Franja cosecha superior firma -->
                <tr>
                  <td style="background: linear-gradient(90deg, #7A5C2E 0%, #B8860B 40%, #D4A017 60%, #7A5C2E 100%); height:4px; font-size:0;">&nbsp;</td>
                </tr>

                <tr>
                  <td style="padding:30px 48px 28px 48px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                      <tr>

                        <!-- Datos remitente -->
                        <td style="vertical-align:top; padding-right:28px; border-right:1px solid rgba(255,255,255,0.1);">
                          <p style="margin:0 0 2px 0; font-family:'Libre Baskerville', Georgia, serif; font-size:16px; font-weight:700; color:#F5EDD8; letter-spacing:0.5px;">Ventas Z4</p>
                          <p style="margin:0 0 18px 0; font-size:10px; color:#D4A017; text-transform:uppercase; letter-spacing:3px; font-weight:600;">Agropecuaria Z4</p>

                          <!-- Línea ornamental -->
                          <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:14px;">
                            <tr>
                              <td style="width:30px; height:1px; background-color:#D4A017;"></td>
                              <td style="width:8px; height:1px; background-color:rgba(212,160,23,0.3);"></td>
                            </tr>
                          </table>

                          <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:8px;">
                            <tr>
                              <td style="padding-right:8px; vertical-align:middle;">
                                <span style="font-size:12px; color:#D4A017;">✉</span>
                              </td>
                              <td>
                                <a href="mailto:contacto@agropecuariaz4.com" style="font-size:12px; color:#C8E6B0; text-decoration:none;">contacto@agropecuariaz4.com</a>
                              </td>
                            </tr>
                          </table>
                          <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:8px;">
                            <tr>
                              <td style="padding-right:8px; vertical-align:middle;">
                                <span style="font-size:12px; color:#D4A017;">✆</span>
                              </td>
                              <td>
                                <span style="font-size:12px; color:#C8E6B0;">+52 33 1779 3649</span>
                              </td>
                            </tr>
                          </table>
                          <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:8px;">
                            <tr>
                              <td style="padding-right:8px; vertical-align:top; padding-top:2px;">
                                <span style="font-size:11px; color:#7A9A60;">⊙</span>
                              </td>
                              <td>
                                <span style="font-size:11px; color:#7A9A60; line-height:1.5;">Pablo Villaseñor 128, Arcos Vallarta<br/>Guadalajara, Jalisco</span>
                              </td>
                            </tr>
                          </table>
                          <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                              <td style="padding-right:8px; vertical-align:middle;">
                                <span style="font-size:11px; color:#7A9A60;">◷</span>
                              </td>
                              <td>
                                <span style="font-size:11px; color:#7A9A60;">Lun–Vie 7:00–17:00 · Sáb 7:00–12:00</span>
                              </td>
                            </tr>
                          </table>
                        </td>

                        <!-- Sello circular derecho -->
                        <td width="150" style="vertical-align:middle; text-align:center; padding-left:28px;">
                          <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
                            <tr>
                              <td style="width:120px; height:120px; border:2px solid #D4A017; border-radius:50%; text-align:center; vertical-align:middle; background-color:rgba(212,160,23,0.06);">
                                <p style="margin:0 0 2px 0; font-size:28px; line-height:1;">🌾</p>
                                <p style="margin:0 0 1px 0; font-family:'Libre Baskerville', sans-serif; font-size:11px; font-weight:700; color:#D4A017; letter-spacing:1px; text-transform:uppercase;">Z4</p>
                                <p style="margin:0; font-size:8px; color:#7A9A60; letter-spacing:2px; text-transform:uppercase;">Campo · Calidad</p>
                              </td>
                            </tr>
                          </table>
                          <a href="https://agropecuaria-z4.com" style="display:block; margin-top:12px; font-size:10px; color:#D4A017; text-decoration:none; letter-spacing:2px; text-transform:uppercase; font-weight:600;">agropecuaria-z4.com</a>
                        </td>

                      </tr>
                    </table>
                  </td>
                </tr>

                <!-- Franja final cosecha -->
                <tr>
                  <td style="background: repeating-linear-gradient(90deg, #3A5A2A 0px, #3A5A2A 14px, #4A7A38 14px, #4A7A38 28px); height:5px; font-size:0;">&nbsp;</td>
                </tr>

                <!-- Disclaimer -->
                <tr>
                  <td style="background-color:#1E2E12; padding:12px 48px;">
                    <p style="margin:0; font-size:9px; color:#3A5A2A; line-height:1.7; text-align:center;">
                      Este correo es confidencial y destinado exclusivamente al destinatario indicado. Queda prohibida su distribución no autorizada. © 2025 Agropecuaria Z4 · Guadalajara, Jalisco · Todos los derechos reservados. 🌾 Cultivando calidad para Jalisco.
                    </p>
                  </td>
                </tr>

              </table>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>

</body>
</html>

HTML;
}



function generaCorreoVos($mensaje)
{
  return <<<HTML
    <!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Correo Vostokoff</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@300;400;500&family=Rajdhani:wght@400;500;600;700&display=swap" rel="stylesheet"/>
</head>
<body style="margin:0; padding:0; background-color:#E9EEF4; font-family:'Rajdhani', 'Segoe UI', sans-serif;">

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
    style="background-color:#E9EEF4;
           background-image: linear-gradient(rgba(100,130,160,0.07) 1px, transparent 1px),
                             linear-gradient(90deg, rgba(100,130,160,0.07) 1px, transparent 1px);
           background-size: 28px 28px;
           padding:36px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="620" cellpadding="0" cellspacing="0" style="max-width:620px; width:100%;">

          <!-- ══════════════════════════════════
               ID STRIP — Número de documento técnico
          ══════════════════════════════════ -->
          <tr>
            <td style="padding:0 0 8px 0;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="vertical-align:middle;">
                    <span style="font-family:'DM Mono', monospace; font-size:9px; color:#8AA0B8; letter-spacing:2px;">VTK-COMM // DOC#&nbsp;</span>
                    <span style="font-family:'DM Mono', monospace; font-size:9px; color:#4A7FA8;">2025-MSG-001</span>
                  </td>
                  <td align="right" style="vertical-align:middle;">
                    <span style="font-family:'DM Mono', monospace; font-size:9px; color:#8AA0B8; letter-spacing:1px;">CLASIFICACIÓN: OFICIAL</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- ══════════════════════════════════
               CABECERA — Panel de instrumentación
          ══════════════════════════════════ -->
          <tr>
            <td style="padding:0; border-radius:6px 6px 0 0; overflow:hidden;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">

                <!-- Barra de diagnóstico superior -->
                <tr>
                  <td width="8" style="background-color:#0B3D6E; font-size:0;">&nbsp;</td>
                  <td width="4" style="background-color:#1A6EA8; font-size:0;">&nbsp;</td>
                  <td width="4" style="background-color:#4A9FD4; font-size:0;">&nbsp;</td>
                  <td style="background-color:#051E35; font-size:0; height:6px;">&nbsp;</td>
                </tr>

                <!-- Panel principal -->
                <tr>
                  <td colspan="4" style="background-color:#051E35; padding:0;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                      <tr>

                        <!-- Bloque logo + ID -->
                        <td style="padding:32px 0 32px 36px; vertical-align:middle; border-right:1px solid rgba(74,159,212,0.15);">
                          <img src="https://vostokoff.com/logov.png"
                               alt="Vostokoff"
                               width="130"
                               style="display:block; border:0; margin-bottom:16px;"/>

                          <!-- Línea técnica bajo logo -->
                          <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                              <td style="width:6px; height:6px; background-color:#4A9FD4; border-radius:50%;"></td>
                              <td style="width:40px; height:1px; background-color:#1A6EA8;"></td>
                              <td style="width:4px; height:4px; background-color:rgba(74,159,212,0.4); border-radius:50%;"></td>
                              <td style="width:20px; height:1px; background-color:rgba(26,110,168,0.3);"></td>
                            </tr>
                          </table>

                          <p style="margin:10px 0 0 0; font-family:'DM Mono', monospace; font-size:9px; color:#4A9FD4; letter-spacing:3px; text-transform:uppercase;">SECTOR: MÉDICO &amp; SALUD</p>
                        </td>

                        <!-- Panel de métricas derecho -->
                        <td style="padding:28px 36px 28px 32px; vertical-align:middle;">

                          <!-- Titulo principal -->
                          <p style="margin:0 0 4px 0; font-family:'DM Mono', monospace; font-size:9px; color:#4A9FD4; letter-spacing:3px;">PROVEEDOR AUTORIZADO //</p>
                          <p style="margin:0 0 18px 0; font-family:'Rajdhani', sans-serif; font-size:26px; font-weight:700; color:#ffffff; line-height:1.1; text-transform:uppercase; letter-spacing:1px;">Productos<br/>Farmacéuticos<br/><span style="color:#4A9FD4;">&amp; Equipo Médico</span></p>

                          <!-- Metric badge -->
                          <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                              <td style="background-color:rgba(74,159,212,0.1); border:1px solid rgba(74,159,212,0.3); border-radius:3px; padding:8px 16px; text-align:center;">
                                <p style="margin:0; font-family:'Rajdhani', sans-serif; font-size:22px; font-weight:700; color:#4A9FD4; line-height:1;">8,648</p>
                                <p style="margin:2px 0 0 0; font-family:'DM Mono', monospace; font-size:8px; color:#5A80A0; letter-spacing:2px; text-transform:uppercase;">Clientes · Satisfechos</p>
                              </td>
                            </tr>
                          </table>

                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>

                <!-- Barra de estado inferior -->
                <tr>
                  <td colspan="4" style="background-color:#071628; padding:8px 36px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                      <tr>
                        <td style="vertical-align:middle;">
                          <span style="display:inline-block; width:6px; height:6px; background-color:#00C896; border-radius:50%; vertical-align:middle;"></span>
                          <span style="font-family:'DM Mono', monospace; font-size:9px; color:#3A6A8A; margin-left:6px; letter-spacing:1px;">SISTEMA OPERATIVO</span>
                        </td>
                        <td style="text-align:center; vertical-align:middle;">
                          <span style="font-family:'DM Mono', monospace; font-size:8px; color:#1E4060; letter-spacing:2px;">— — VOSTOKOFF S.A. — —</span>
                        </td>
                        <td align="right" style="vertical-align:middle;">
                          <span style="font-family:'DM Mono', monospace; font-size:9px; color:#3A6A8A; letter-spacing:1px;">GUADALAJARA, JAL.</span>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>

              </table>
            </td>
          </tr>

          <!-- ══════════════════════════════════
               CUERPO — Panel de datos
          ══════════════════════════════════ -->
          <tr>
            <td style="background-color:#F4F7FA; padding:0;">

              <!-- Línea técnica de separación -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="width:36px; height:3px; background-color:#1A6EA8;"></td>
                  <td style="height:3px; background-color:#DDE6EF;"></td>
                  <td style="width:36px; height:3px; background-color:#4A9FD4;"></td>
                </tr>
              </table>

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="padding:36px 44px 32px 44px;">

                    <!-- Header de sección con monospace -->
                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:22px;">
                      <tr>
                        <td style="background-color:#EAF1F8; border:1px solid #C8D8E8; border-radius:3px; padding:6px 16px;">
                          <span style="font-family:'DM Mono', monospace; font-size:9px; color:#1A6EA8; letter-spacing:3px; text-transform:uppercase;">Estimado //</span>
                          <span style="font-family:'Rajdhani', sans-serif; font-size:16px; font-weight:600; color:#051E35; margin-left:10px;">Cliente</span>
                        </td>
                      </tr>
                    </table>

                  
                    <p style="margin:0; font-size:15px; color:#2A3F55; line-height:1.85; font-weight:400;">
                      $mensaje
                    </p>

                  </td>
                </tr>
              </table>

              <!-- Líneas de categorías tipo readout técnico -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                style="background-color:#EAF1F8; border-top:1px solid #C8D8E8; border-bottom:1px solid #C8D8E8;">
                <tr>
                  <td style="padding:20px 44px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">

                      <tr>
                        <td style="padding:5px 0; border-bottom:1px solid #D4E3EF;">
                          <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                              <td width="16" style="vertical-align:middle; padding-right:10px;">
                                <span style="display:inline-block; width:8px; height:8px; background-color:#1A6EA8; border-radius:1px; vertical-align:middle;"></span>
                              </td>
                              <td style="vertical-align:middle;">
                                <span style="font-family:'DM Mono', monospace; font-size:10px; color:#4A7FA8; letter-spacing:1px; text-transform:uppercase;">CAT-01</span>
                                <span style="font-family:'Rajdhani', sans-serif; font-size:14px; font-weight:600; color:#051E35; margin-left:14px;">Productos Farmacéuticos</span>
                              </td>
                              <td align="right">
                                <span style="font-family:'DM Mono', monospace; font-size:9px; color:#A0B8CC;">→ DISPONIBLE</span>
                              </td>
                            </tr>
                          </table>
                        </td>
                      </tr>

                      <tr>
                        <td style="padding:5px 0; border-bottom:1px solid #D4E3EF;">
                          <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                              <td width="16" style="vertical-align:middle; padding-right:10px;">
                                <span style="display:inline-block; width:8px; height:8px; background-color:#4A9FD4; border-radius:1px; vertical-align:middle;"></span>
                              </td>
                              <td style="vertical-align:middle;">
                                <span style="font-family:'DM Mono', monospace; font-size:10px; color:#4A7FA8; letter-spacing:1px; text-transform:uppercase;">CAT-02</span>
                                <span style="font-family:'Rajdhani', sans-serif; font-size:14px; font-weight:600; color:#051E35; margin-left:14px;">Equipo y Mobiliario Médico</span>
                              </td>
                              <td align="right">
                                <span style="font-family:'DM Mono', monospace; font-size:9px; color:#A0B8CC;">→ DISPONIBLE</span>
                              </td>
                            </tr>
                          </table>
                        </td>
                      </tr>

                      <tr>
                        <td style="padding:5px 0;">
                          <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                              <td width="16" style="vertical-align:middle; padding-right:10px;">
                                <span style="display:inline-block; width:8px; height:8px; background-color:#00C896; border-radius:1px; vertical-align:middle;"></span>
                              </td>
                              <td style="vertical-align:middle;">
                                <span style="font-family:'DM Mono', monospace; font-size:10px; color:#4A7FA8; letter-spacing:1px; text-transform:uppercase;">CAT-03</span>
                                <span style="font-family:'Rajdhani', sans-serif; font-size:14px; font-weight:600; color:#051E35; margin-left:14px;">Instrumental Médico y de Laboratorio</span>
                              </td>
                              <td align="right">
                                <span style="font-family:'DM Mono', monospace; font-size:9px; color:#A0B8CC;">→ DISPONIBLE</span>
                              </td>
                            </tr>
                          </table>
                        </td>
                      </tr>

                    </table>
                  </td>
                </tr>
              </table>

            </td>
          </tr>

          <!-- ══════════════════════════════════
               FIRMA — Terminal de datos
          ══════════════════════════════════ -->
          <tr>
            <td style="background-color:#071628; padding:0; border-radius:0 0 6px 6px; overflow:hidden;">

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="padding:28px 36px 26px 36px;">

                    <!-- Header firma -->
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
                      <tr>
                        <td>
                          <p style="margin:0; font-family:'DM Mono', monospace; font-size:8px; color:#1A6EA8; letter-spacing:3px; text-transform:uppercase;">// FIRMANTE AUTORIZADO</p>
                        </td>
                        <td align="right">
                          <p style="margin:0; font-family:'DM Mono', monospace; font-size:8px; color:#0D3050; letter-spacing:2px;">VOSTOKOFF · SECTOR MÉDICO</p>
                        </td>
                      </tr>
                    </table>

                    <!-- Línea divisoria técnica -->
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
                      <tr>
                        <td style="height:1px; background-color:#0D3050; font-size:0;">&nbsp;</td>
                      </tr>
                    </table>

                    <!-- Datos remitente + contacto en grid -->
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                      <tr>

                        <!-- Nombre y cargo -->
                        <td style="vertical-align:top; width:45%; padding-right:24px; border-right:1px solid #0D3050;">
                          <p style="margin:0 0 2px 0; font-family:'Rajdhani', sans-serif; font-size:18px; font-weight:700; color:#ffffff; text-transform:uppercase; letter-spacing:1px;">Ventas y seguimiento</p>
                          <p style="margin:0 0 16px 0; font-family:'DM Mono', monospace; font-size:9px; color:#4A9FD4; letter-spacing:2px; text-transform:uppercase;">Vostokoff</p>

                          <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:8px;">
                            <tr>
                              <td style="font-family:'DM Mono', monospace; font-size:9px; color:#1A4060; padding-right:8px; letter-spacing:1px; text-transform:uppercase;">MAIL</td>
                              <td>
                                <a href="mailto:contacto@vostokoff.com" style="font-family:'DM Mono', monospace; font-size:10px; color:#4A9FD4; text-decoration:none;">contacto@vostokoff.com</a>
                              </td>
                            </tr>
                          </table>
                          <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:8px;">
                            <tr>
                              <td style="font-family:'DM Mono', monospace; font-size:9px; color:#1A4060; padding-right:8px; letter-spacing:1px; text-transform:uppercase;">TEL.</td>
                              <td>
                                <span style="font-family:'DM Mono', monospace; font-size:10px; color:#7AAAC8;">3311828548</span>
                              </td>
                            </tr>
                          </table>
                          <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                              <td style="font-family:'DM Mono', monospace; font-size:9px; color:#1A4060; padding-right:8px; letter-spacing:1px; text-transform:uppercase; vertical-align:top;">DIR.</td>
                              <td>
                                <span style="font-family:'DM Mono', monospace; font-size:9px; color:#3A6080; line-height:1.6;">AV. Guadalupe #4872, Col. Jardines<br/>de Guadalupe, C.P. 45030, Zapopan, JAL.</span>
                              </td>
                            </tr>
                          </table>
                        </td>

                        <!-- Panel derecho: URL + status -->
                        <td style="vertical-align:top; padding-left:24px;">

                          <!-- Terminal readout -->
                          <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                            style="background-color:#030E1A; border:1px solid #0D3050; border-radius:4px; padding:14px 16px;">
                            <tr>
                              <td>
                                <p style="margin:0 0 8px 0; font-family:'DM Mono', monospace; font-size:8px; color:#0D3050; letter-spacing:1px;">$ connect --host</p>
                                <p style="margin:0 0 12px 0; font-family:'DM Mono', monospace; font-size:12px; color:#4A9FD4; letter-spacing:1px;">vostokoff.com</p>
                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:10px;">
                                  <tr>
                                    <td style="background-color:#00C896; width:6px; height:6px; border-radius:50%;"></td>
                                    <td style="padding-left:6px; font-family:'DM Mono', monospace; font-size:8px; color:#00C896; letter-spacing:2px;">ONLINE</td>
                                  </tr>
                                </table>
                                <p style="margin:0; font-family:'DM Mono', monospace; font-size:8px; color:#0D3050; letter-spacing:1px; line-height:1.6;">Lun–Vie: 08:00–18:00<br/>Sector: Salud &amp; Farmacia</p>
                              </td>
                            </tr>
                          </table>

                        </td>
                      </tr>
                    </table>

                  </td>
                </tr>
              </table>

              <!-- Barra final tricolor técnica -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="width:8px; background-color:#0B3D6E; height:4px; font-size:0;">&nbsp;</td>
                  <td style="width:8px; background-color:#1A6EA8; height:4px; font-size:0;">&nbsp;</td>
                  <td style="background-color:#4A9FD4; height:4px; font-size:0;">&nbsp;</td>
                  <td style="width:8px; background-color:#00C896; height:4px; font-size:0;">&nbsp;</td>
                </tr>
              </table>

              <!-- Disclaimer -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="background-color:#020C16; padding:10px 36px;">
                    <p style="margin:0; font-family:'DM Mono', monospace; font-size:8px; color:#0D2A40; line-height:1.7; text-align:center;">
                      // MSG:CONFIDENTIAL — Este mensaje es de uso exclusivo del destinatario. Distribución no autorizada prohibida. © 2025 Vostokoff · Guadalajara, México · Todos los derechos reservados.
                    </p>
                  </td>
                </tr>
              </table>

            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>

</body>
</html>

HTML;
}

function generaCorreoMaguire($mensaje)
{
  return <<<HTML
    <!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Correo Corporativo – Maguire Renta de Maquinaria</title>
</head>
<body style="margin:0; padding:0; background-color:#f2f2f2; font-family: 'Segoe UI', Arial, sans-serif;">

  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f2f2f2; padding: 30px 0;">
    <tr>
      <td align="center">

        <!-- Contenedor principal -->
        <table width="620" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff; border-radius:6px; overflow:hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.12);">

          <!-- ═══════════════ CABECERA ═══════════════ -->
          <tr>
            <td style="background-color:#1a1a1a; padding: 0;">
              <!-- Barra de acento amarilla -->
              <table width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="height:5px; background-color:#f5a623;"></td>
                </tr>
              </table>
              <!-- Logo + Nombre -->
              <table width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="padding: 22px 32px;">
                    <table cellpadding="0" cellspacing="0" border="0">
                      <tr>
                        <td style="vertical-align:middle; padding-right:16px;">
                          <img src="https://maguire.mx/assets/img/maguire_logo.jpg"
                               alt="Maguire Logo"
                               width="120"
                               style="display:block; border-radius:4px;" />
                        </td>
                        <td style="vertical-align:middle; border-left:2px solid #f5a623; padding-left:16px;">
                          <p style="margin:0; color:#f5a623; font-size:13px; font-weight:600; letter-spacing:2px; text-transform:uppercase;">Renta de Maquinaria</p>
                          <p style="margin:4px 0 0; color:#ffffff; font-size:11px; letter-spacing:1px; opacity:0.6; text-transform:uppercase;">Soluciones para la Industria</p>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- ═══════════════ BANNER DECORATIVO ═══════════════ -->
          <tr>
            <td style="background: linear-gradient(135deg, #2c2c2c 0%, #1a1a1a 60%, #f5a623 100%); padding: 28px 32px 24px;">
              <p style="margin:0; color:#f5a623; font-size:11px; font-weight:700; letter-spacing:3px; text-transform:uppercase;">Estimado/a Cliente,</p>
              <h1 style="margin: 10px 0 0; color:#ffffff; font-size:26px; font-weight:700; line-height:1.3;">
                Bienvenido a <span style="color:#f5a623;">Maguire</span>
              </h1>
              <p style="margin:8px 0 0; color:#cccccc; font-size:13px; line-height:1.6;">
                Más de 10 años brindando soluciones confiables en renta de maquinaria pesada para la industria.
              </p>
            </td>
          </tr>

          <!-- ═══════════════ CUERPO DEL MENSAJE ═══════════════ -->
          <tr>
            <td style="padding: 36px 32px 28px;">

           
              <p style="margin:0 0 28px; color:#333333; font-size:15px; line-height:1.7;">
              $mensaje
            </p>

              <!-- Bloque de categorías -->
              <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
                <tr>
                  <td style="background-color:#f9f9f9; border-left:4px solid #f5a623; border-radius:0 4px 4px 0; padding:18px 20px;">
                    <p style="margin:0 0 10px; color:#1a1a1a; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:1px;">Nuestras Categorías de Renta</p>
                    <table width="100%" cellpadding="0" cellspacing="0" border="0">
                      <tr>
                        <td width="50%" style="padding:4px 0; color:#555555; font-size:14px;">&#9654;&nbsp; Elevadores</td>
                        <td width="50%" style="padding:4px 0; color:#555555; font-size:14px;">&#9654;&nbsp; Compactadores</td>
                      </tr>
                      <tr>
                        <td width="50%" style="padding:4px 0; color:#555555; font-size:14px;">&#9654;&nbsp; Trailers</td>
                        <td width="50%" style="padding:4px 0; color:#555555; font-size:14px;">&#9654;&nbsp; Montacargas</td>
                      </tr>
                      <tr>
                        <td width="50%" style="padding:4px 0; color:#555555; font-size:14px;">&#9654;&nbsp; Plantas Generadoras</td>
                        <td width="50%" style="padding:4px 0; color:#555555; font-size:14px;">&#9654;&nbsp; Control de Temperatura</td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>

              <!-- CTA -->
              <table cellpadding="0" cellspacing="0" border="0" style="margin-bottom:10px;">
                <tr>
                  <td style="background-color:#f5a623; border-radius:4px; padding:0;">
                    <a href="mailto:contacto@maguire.mx"
                       style="display:inline-block; padding:13px 30px; color:#1a1a1a; font-size:14px; font-weight:700; text-decoration:none; letter-spacing:0.5px; text-transform:uppercase;">
                      ✉ Solicitar Cotización
                    </a>
                  </td>
                </tr>
              </table>

            </td>
          </tr>

          <!-- ═══════════════ DIVISOR ═══════════════ -->
          <tr>
            <td style="padding: 0 32px;">
              <table width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="border-top:1px solid #e8e8e8; height:1px;"></td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- ═══════════════ FIRMA ═══════════════ -->
          <tr>
            <td style="padding: 28px 32px 32px;">

              <table width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <!-- Logo pequeño en firma -->
                  <td width="80" style="vertical-align:top; padding-right:20px;">
                    <img src="https://maguire.mx/assets/img/maguire_logo.jpg"
                         alt="Maguire"
                         width="70"
                         style="display:block; border-radius:4px; border:2px solid #f5a623;" />
                  </td>
                  <!-- Datos de contacto -->
                  <td style="vertical-align:top; border-left:3px solid #f5a623; padding-left:20px;">
                    <p style="margin:0 0 2px; color:#1a1a1a; font-size:15px; font-weight:700;">Maguire Renta de Maquinaria</p>
                    <p style="margin:0 0 10px; color:#f5a623; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:1.5px;">Soluciones para la Industria</p>

                    <!-- Dirección -->
                    <table cellpadding="0" cellspacing="0" border="0" style="margin-bottom:5px;">
                      <tr>
                        <td style="vertical-align:top; padding-right:8px; padding-top:1px; color:#f5a623; font-size:13px;">&#9679;</td>
                        <td style="color:#555555; font-size:13px; line-height:1.5;">
                          Pablo Villaseñor 128, Col. Arcos Vallarta<br/>
                          C.P. 44130, Guadalajara, Jalisco, México
                        </td>
                      </tr>
                    </table>

                    <!-- Teléfono -->
                    <table cellpadding="0" cellspacing="0" border="0" style="margin-bottom:5px;">
                      <tr>
                        <td style="vertical-align:top; padding-right:8px; color:#f5a623; font-size:13px;">&#9679;</td>
                        <td>
                          <a href="tel:3323239880" style="color:#333333; font-size:13px; text-decoration:none; font-weight:600;">
                            (+52) 33 23 23 9880
                          </a>
                        </td>
                      </tr>
                    </table>

                    <!-- Correo -->
                    <table cellpadding="0" cellspacing="0" border="0" style="margin-bottom:5px;">
                      <tr>
                        <td style="vertical-align:top; padding-right:8px; color:#f5a623; font-size:13px;">&#9679;</td>
                        <td>
                          <a href="mailto:contacto@maguire.mx" style="color:#f5a623; font-size:13px; text-decoration:none; font-weight:600;">
                            contacto@maguire.mx
                          </a>
                        </td>
                      </tr>
                    </table>

                    <!-- Sitio web -->
                    <table cellpadding="0" cellspacing="0" border="0">
                      <tr>
                        <td style="vertical-align:top; padding-right:8px; color:#f5a623; font-size:13px;">&#9679;</td>
                        <td>
                          <a href="https://maguire.mx" style="color:#333333; font-size:13px; text-decoration:none;">
                            www.maguire.mx
                          </a>
                        </td>
                      </tr>
                    </table>

                  </td>
                </tr>
              </table>

            </td>
          </tr>

          <!-- ═══════════════ PIE DE PÁGINA ═══════════════ -->
          <tr>
            <td style="background-color:#1a1a1a; padding:0;">
              <!-- Barra de acento -->
              <table width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="height:4px; background-color:#f5a623;"></td>
                </tr>
              </table>
              <table width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="padding:16px 32px; text-align:center;">
                    <p style="margin:0; color:#888888; font-size:11px; line-height:1.6;">
                      Este correo y sus adjuntos son confidenciales y están destinados exclusivamente al destinatario indicado.<br/>
                      Si recibió este mensaje por error, por favor notifíquenos y elimínelo de inmediato.
                    </p>
                    <p style="margin:8px 0 0; color:#f5a623; font-size:11px; font-weight:600;">
                      © 2025 Maguire Renta de Maquinaria · Guadalajara, Jalisco, México
                    </p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

        </table>
        <!-- /Contenedor principal -->

      </td>
    </tr>
  </table>

</body>
</html>

HTML;
}


function generaCorreoCaudal($mensaje)
{
  return <<<HTML
   
      <!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Caudal Legal Integral</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f4;">

<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse; background-color:#f4f4f4;">
<tr>
<td align="center" style="padding:20px 10px;">

<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600" style="width:100%; max-width:600px; border-collapse:collapse; background-color:#ffffff; border:1px solid #e0e0e0; font-family:Arial, Helvetica, sans-serif;">

  <!-- HEADER -->
  <tr>
    <td style="background-color:#0f2b46; padding:16px 24px;">
      <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;">
        <tr>
          <td style="vertical-align:middle;">
            <img src="https://caudallegal.com/logo-blanco.png" alt="Caudal Legal Integral" height="40" style="display:block; height:40px; width:auto; border:0;">
          </td>
          <td align="right" style="vertical-align:middle;">
            <span style="font-family:Arial, Helvetica, sans-serif; font-size:11px; color:#c9a961; letter-spacing:1.5px; text-transform:uppercase;">Consultoría · Contabilidad · Derecho</span>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- LÍNEA DORADA -->
  <tr><td style="background-color:#c9a961; height:2px; line-height:2px; font-size:0;">&nbsp;</td></tr>

  <!-- ÁREA DE CONTENIDO -->
  <tr>
    <td style="padding:28px 28px 24px 28px; font-family:Arial, Helvetica, sans-serif; font-size:14px; line-height:22px; color:#4a4a4a;">
      
      <p style="margin:0;">$mensaje</p>
    </td>
  </tr>

  <!-- LÍNEA DORADA -->
  <tr><td style="background-color:#c9a961; height:2px; line-height:2px; font-size:0;">&nbsp;</td></tr>

  <!-- FIRMA -->
  <tr>
    <td style="background-color:#0f2b46; padding:18px 24px;">
      <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;">
        <tr>
          <td style="vertical-align:top; padding-right:20px; width:110px;">
            <img src="https://caudallegal.com/logo1.png" alt="Caudal Legal Integral" width="100" style="display:block; width:100px; height:auto; border:0;">
          </td>
          <td style="border-left:1px solid #2a4a6a; padding-left:20px; vertical-align:top;">
            <p style="margin:0 0 2px 0; font-family:Arial, Helvetica, sans-serif; font-size:13px; font-weight:bold; color:#ffffff;">Cudal legal</p>
            <p style="margin:0 0 8px 0; font-family:Arial, Helvetica, sans-serif; font-size:11px; color:#c9a961;">Departamenteo de Facturacion</p>
            <p style="margin:0 0 2px 0; font-family:Arial, Helvetica, sans-serif; font-size:11px; color:#c8d3de;">contacto@caudallegal.com</p>
            <p style="margin:0 0 2px 0; font-family:Arial, Helvetica, sans-serif; font-size:11px; color:#c8d3de;">caudallegal.com</p>
            <p style="margin:6px 0 0 0; font-family:Arial, Helvetica, sans-serif; font-size:10px; color:#6f8194;">Calle Barranquillas 2923 Int. 5, Col. Colomos Providencia, Guadalajara, Jal.</p>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- PIE -->
  <tr>
    <td align="center" style="background-color:#0a1f33; padding:10px 24px;">
      <p style="margin:0; font-family:Arial, Helvetica, sans-serif; font-size:10px; line-height:15px; color:#6f8194;">Este mensaje y sus anexos son confidenciales y de uso exclusivo del destinatario. &copy; 2026 Caudal Legal Integral, S.C.</p>
    </td>
  </tr>

</table>

</td>
</tr>
</table>

</body>
</html>
HTML;
}

function generaCorreoVistalomas($mensaje)
{
  return <<<HTML
<div style="background:#FAF7EF; padding:24px 12px; border-radius:12px;">

    <h2 class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">
        Vista previa del correo HTML de Vista Lomas Alimentos y Banquetes
    </h2>

    <div style="max-width:600px; margin:0 auto; background:#FFFFFF; border-radius:8px; overflow:hidden; font-family:Arial, Helvetica, sans-serif;">

        <div style="padding:32px 30px 24px; background:#FFFDF8; border-bottom:3px solid #E8A317; text-align:center;">

            <!-- LOGO -->
            <div style="text-align:center; margin:0 0 25px 0;">
                <img 
                    src="https://sistema14.com/app//img/empresas/Imagen_6a7f7f5d961ca_1657.jpg"
                    alt="Vista Lomas Alimentos y Banquetes"
                    width="180"
                    style="
                        display:block;
                        width:180px;
                        max-width:70%;
                        height:auto;
                        margin:0 auto;
                        border:0;
                        outline:none;
                        text-decoration:none;
                    "
                >
            </div>

            <div style="padding:10px 40px 0; text-align:center;">

                <p style="margin:0; font-size:12px; line-height:18px; letter-spacing:3px; color:#E8A317; text-transform:uppercase; font-weight:bold;">
                    ALIMENTOS Y BANQUETES
                </p>

                <h1 style="margin:8px 0 0; font-size:30px; line-height:38px; color:#4A2C1A; font-weight:bold; font-family:Arial, Helvetica, sans-serif;">
                    El sabor que tu evento merece
                </h1>

                <p style="margin:16px 0 28px; font-size:16px; line-height:26px; color:#6B5B4E;">
                    Menus personalizados, servicio profesional y una presentacion impecable para bodas, eventos corporativos y celebraciones inolvidables.
                </p>

                <div style="display:inline-block; padding:15px 38px; background:#E8A317; border-radius:4px; color:#FFFFFF; font-size:15px; font-weight:bold; letter-spacing:0.5px;">
                    Cotiza tu evento
                </div>

            </div>

            <div style="margin:36px 30px 0; border-top:1px solid #EFE7D6;"></div>

            <h2 style="margin:36px 0 12px; font-size:20px; line-height:26px; color:#4A2C1A; font-weight:bold; text-align:center; font-family:Arial, Helvetica, sans-serif;">
                $mensaje
            </h2>

            <div style="display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:16px; padding:12px 30px 20px;">

                <div style="background:#FFFBF2; border-radius:6px; padding:20px 10px; text-align:center;">
                    <div style="font-size:24px; color:#E8A317; line-height:48px;">&#10022;</div>
                    <p style="margin:12px 0 4px; font-size:15px; color:#4A2C1A; font-weight:bold;">Bodas</p>
                    <p style="margin:0; font-size:13px; line-height:19px; color:#7A6A5C;">Banquetes de tres tiempos</p>
                </div>

                <div style="background:#FFFBF2; border-radius:6px; padding:20px 10px; text-align:center;">
                    <div style="font-size:24px; color:#E8A317; line-height:48px;">&#10022;</div>
                    <p style="margin:12px 0 4px; font-size:15px; color:#4A2C1A; font-weight:bold;">Corporativo</p>
                    <p style="margin:0; font-size:13px; line-height:19px; color:#7A6A5C;">Coffee breaks y comidas</p>
                </div>

                <div style="background:#FFFBF2; border-radius:6px; padding:20px 10px; text-align:center;">
                    <div style="font-size:24px; color:#E8A317; line-height:48px;">&#10022;</div>
                    <p style="margin:12px 0 4px; font-size:15px; color:#4A2C1A; font-weight:bold;">Sociales</p>
                    <p style="margin:0; font-size:13px; line-height:19px; color:#7A6A5C;">XV anos y aniversarios</p>
                </div>

            </div>

            <div style="padding:16px 30px 40px;">

                <div style="background:#4A2C1A; border-radius:6px; padding:30px 24px; text-align:center;">

                    <p style="margin:0 0 6px; font-size:11px; line-height:16px; letter-spacing:2px; color:#E8A317; text-transform:uppercase; font-weight:bold;">
                        AGENDA UNA DEGUSTACION
                    </p>

                    <p style="margin:0 0 18px; font-size:18px; line-height:26px; color:#FFFFFF;">
                        Prueba nuestro menu antes de decidir
                    </p>

                    <span style="font-size:22px; line-height:28px; color:#F0B733; font-weight:bold;">
                        33 0000 0000
                    </span>

                </div>

            </div>

            <div style="padding:28px 30px; background:#FFFBF2; border-top:1px solid #EFE7D6; text-align:center;">

                <p style="margin:0 0 8px; font-size:15px; line-height:22px; color:#4A2C1A; font-weight:bold;">
                    Vista Lomas
                </p>

                <p style="margin:0 0 14px; font-size:13px; line-height:20px; color:#7A6A5C;">
                    Alimentos y Banquetes<br>
                    Guadalajara, Jalisco, Mexico
                </p>

                <p style="margin:0 0 16px; font-size:13px; line-height:20px; color:#C98A0E;">
                    contacto@vistalomas.mx
                </p>

                <p style="margin:0; font-size:11px; line-height:18px; color:#A0937F;">
                    Recibes este correo porque te suscribiste a nuestras novedades.<br>
                    <span style="text-decoration:underline;">Cancelar suscripcion</span>
                    &nbsp;|&nbsp;
                    <span style="text-decoration:underline;">Aviso de privacidad</span>
                </p>

            </div>

        </div>

    </div>

</div>
HTML;
}



function generaCorreoUmbrella($mensaje)
{
  return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">

<meta name="color-scheme" content="dark">
<meta name="supported-color-schemes" content="dark">

<title>Asesoría Comercial Umbrella</title>

<!--[if mso]>
<style>
table,td,div,p,a{
  font-family:Arial,Helvetica,sans-serif !important;
}
</style>
<![endif]-->

</head>

<body style="
margin:0;
padding:0;
background-color:#0b0c0e;
color:#f5f5f5;
-webkit-text-size-adjust:100%;
-ms-text-size-adjust:100%;
">

<span style="
display:none;
font-size:1px;
color:#0b0c0e;
line-height:1px;
max-height:0;
max-width:0;
opacity:0;
overflow:hidden;
">
Asesoría Comercial Umbrella — consultoría, capacitación y asesoría en comercialización.
</span>


<table
role="presentation"
cellpadding="0"
cellspacing="0"
border="0"
width="100%"
bgcolor="#0b0c0e"
style="
border-collapse:collapse;
background-color:#0b0c0e;
"
>
<tr>
<td align="center" style="padding:28px 12px;background-color:#0b0c0e;">


<!-- MARCO PRINCIPAL -->
<table
role="presentation"
cellpadding="0"
cellspacing="0"
border="0"
width="600"
bgcolor="#17191d"
style="
width:600px;
max-width:600px;
border-collapse:collapse;
background-color:#17191d;
border:1px solid #303238;
"
>


<!-- ENCABEZADO -->
<tr>
<td
bgcolor="#101114"
style="
padding:22px 32px;
background-color:#101114;
border-bottom:1px solid #303238;
"
>

<table
role="presentation"
cellpadding="0"
cellspacing="0"
border="0"
width="100%"
style="border-collapse:collapse;"
>
<tr>

<td width="46" valign="middle" style="width:46px;">

<img
src="https://comercialumbrella.com/images/logo.png"
width="38"
alt="Asesoría Comercial Umbrella"
style="
display:block;
width:38px;
max-width:38px;
height:auto;
border:0;
outline:none;
text-decoration:none;
"
>

</td>

<td
valign="middle"
align="right"
style="
font-family:Arial,Helvetica,sans-serif;
font-size:11px;
line-height:16px;
letter-spacing:2.4px;
color:#ffffff;
text-transform:uppercase;
mso-line-height-rule:exactly;
"
>
Asesoría Comercial Umbrella
</td>

</tr>
</table>

</td>
</tr>


<!-- CUERPO -->
<tr>
<td
bgcolor="#17191d"
style="
padding:36px 32px 20px 32px;
background-color:#17191d;
"
>

<p style="
margin:0 0 18px 0;
font-family:Arial,Helvetica,sans-serif;
font-size:19px;
line-height:26px;
font-weight:bold;
color:#ffffff;
mso-line-height-rule:exactly;
">
Buenas tardes
</p>

<p style="
margin:0 0 14px 0;
font-family:Arial,Helvetica,sans-serif;
font-size:15px;
line-height:25px;
color:#bfc2c8;
mso-line-height-rule:exactly;
">
Departamento de seguimiento y clientes
</p>

<p style="
margin:0;
font-family:Arial,Helvetica,sans-serif;
font-size:15px;
line-height:25px;
color:#f0f1f3;
mso-line-height-rule:exactly;
">
$mensaje
</p>

</td>
</tr>


<!-- SEPARADOR -->
<tr>
<td
bgcolor="#17191d"
style="
padding:4px 32px 24px 32px;
background-color:#17191d;
"
>

<table
role="presentation"
cellpadding="0"
cellspacing="0"
border="0"
width="100%"
style="
border-collapse:collapse;
border-top:1px solid #303238;
"
>
<tr>
<td style="font-size:1px;line-height:1px;">&nbsp;</td>
</tr>
</table>

</td>
</tr>


<!-- FIRMA -->
<tr>
<td
bgcolor="#121316"
style="
padding:26px 32px;
background-color:#121316;
border-top:1px solid #303238;
"
>

<table
role="presentation"
cellpadding="0"
cellspacing="0"
border="0"
style="border-collapse:collapse;"
>

<tr>

<td
width="72"
valign="top"
style="
width:72px;
padding:0 18px 0 0;
"
>

<img
src="https://comercialumbrella.com/images/umbrella.png"
width="54"
alt="Asesoría Comercial Umbrella"
style="
display:block;
width:54px;
max-width:54px;
height:auto;
border:0;
outline:none;
text-decoration:none;
"
>

</td>


<td
valign="top"
style="
padding:0 0 0 18px;
border-left:2px solid #ffffff;
"
>

<p style="
margin:0;
font-family:Arial,Helvetica,sans-serif;
font-size:15px;
line-height:21px;
font-weight:bold;
color:#ffffff;
mso-line-height-rule:exactly;
">
Umbrella
</p>

<p style="
margin:2px 0 0 0;
font-family:Arial,Helvetica,sans-serif;
font-size:13px;
line-height:20px;
color:#bfc2c8;
mso-line-height-rule:exactly;
">
Clientes y seguimiento
</p>

<p style="
margin:9px 0 0 0;
font-family:Arial,Helvetica,sans-serif;
font-size:13px;
line-height:21px;
color:#bfc2c8;
mso-line-height-rule:exactly;
">

<a
href="tel:+523328713760"
style="
color:#e5e7eb;
text-decoration:none;
"
>
33 2871 3760
</a>

&nbsp; ·&nbsp;

<a
href="mailto:contacto@comercialumbrella.com"
style="
color:#e5e7eb;
text-decoration:none;
"
>
contacto@comercialumbrella.com
</a>

<br>

<a
href="https://comercialumbrella.com"
style="
color:#ffffff;
text-decoration:none;
font-weight:bold;
"
>
comercialumbrella.com
</a>

</p>

</td>
</tr>

</table>

</td>
</tr>


<!-- PIE -->
<tr>
<td
align="center"
bgcolor="#0e0f11"
style="
padding:18px 32px;
background-color:#0e0f11;
border-top:1px solid #292b30;
"
>

<p style="
margin:0;
font-family:Arial,Helvetica,sans-serif;
font-size:11px;
line-height:18px;
color:#858890;
mso-line-height-rule:exactly;
">

<a
href="[URL_CANCELAR_SUSCRIPCION]"
style="
color:#a7a9ae;
text-decoration:underline;
"
>
Cancelar suscripción
</a>

</p>

</td>
</tr>


</table>
<!-- /MARCO PRINCIPAL -->


</td>
</tr>
</table>

</body>
</html>

HTML;
}


function generaCorreoBase($mensaje)
{
  return <<<HTML
    
HTML;
}

