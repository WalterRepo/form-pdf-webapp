const express = require('express');
const router = express.Router();
const multer = require('multer');
const { body, validationResult } = require('express-validator');
const certificatiService = require('../services/certificatiService');
const emailService = require('../services/emailService');

// Configurazione Multer per upload in memoria
// Supporta sia file PDF che immagini (foto del certificato)
const storage = multer.memoryStorage();

const fileFilter = (req, file, cb) => {
    // Accetta PDF e immagini comuni
    const allowedMimeTypes = [
        'application/pdf',
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/heic',
        'image/heif'
    ];

    if (allowedMimeTypes.includes(file.mimetype)) {
        cb(null, true);
    } else {
        cb(new Error('Tipo di file non supportato. Caricare un PDF o un\'immagine (JPG, PNG, HEIC).'), false);
    }
};

const upload = multer({
    storage: storage,
    fileFilter: fileFilter,
    limits: {
        fileSize: 10 * 1024 * 1024 // 10 MB
    }
});

function getSafeCertificateFileName(file) {
    if (file?.originalname && file.originalname.trim()) {
        return file.originalname.trim();
    }

    const extensionByMimeType = {
        'application/pdf': 'pdf',
        'image/jpeg': 'jpg',
        'image/jpg': 'jpg',
        'image/png': 'png',
        'image/heic': 'heic',
        'image/heif': 'heif'
    };

    const extension = extensionByMimeType[file?.mimetype] || 'bin';
    return `certificato_${Date.now()}.${extension}`;
}

/**
 * POST /api/certificati/verifica-socio
 * Verifica se un socio esiste nel database tramite codice fiscale
 * e restituisce i suoi dati anagrafici
 */
router.post('/verifica-socio', [
    body('taxCode')
        .trim()
        .notEmpty()
        .withMessage('Il codice fiscale è obbligatorio')
        .isLength({ min: 16, max: 16 })
        .withMessage('Il codice fiscale deve essere di 16 caratteri')
        .isAlphanumeric()
        .withMessage('Il codice fiscale deve contenere solo lettere e numeri')
], async (req, res) => {
    try {
        // Validazione
        const errors = validationResult(req);
        if (!errors.isEmpty()) {
            return res.status(400).json({
                success: false,
                errors: errors.array()
            });
        }

        const { taxCode } = req.body;

        // Cerca l'handler
        const handler = await certificatiService.findHandlerByTaxCode(taxCode);

        if (!handler) {
            return res.status(404).json({
                success: false,
                message: 'Socio non trovato. Verifica il codice fiscale inserito.'
            });
        }

        // Restituisci i dati anagrafici (senza informazioni sensibili come firebaseId)
        res.json({
            success: true,
            handler: {
                firstName: handler.firstName,
                lastName: handler.lastName,
                email: handler.email,
                taxCode: handler.taxCode,
                medicalCertificateExpiry: handler.medicalCertificateExpiry
            }
        });
    } catch (error) {
        console.error('Errore nella verifica del socio:', error);
        res.status(500).json({
            success: false,
            message: 'Errore nella verifica del socio',
            error: error.message
        });
    }
});

/**
 * POST /api/certificati/upload
 * Upload del certificato medico con file (PDF o foto)
 * Richiede: taxCode, expiryDate, file
 */
router.post('/upload',
    upload.single('certificate'), // Nome del campo file nel form
    [
        body('taxCode')
            .trim()
            .notEmpty()
            .withMessage('Il codice fiscale è obbligatorio')
            .isLength({ min: 16, max: 16 })
            .withMessage('Il codice fiscale deve essere di 16 caratteri')
            .isAlphanumeric()
            .withMessage('Il codice fiscale deve contenere solo lettere e numeri'),
        body('emailConfirm')
            .trim()
            .notEmpty()
            .withMessage('L\'email di conferma è obbligatoria')
            .matches(/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/)
            .withMessage('Formato email non valido')
            .customSanitizer(value => value.toLowerCase()),
        body('expiryDate')
            .notEmpty()
            .withMessage('La data di scadenza è obbligatoria')
            .isISO8601()
            .withMessage('Formato data non valido')
            .custom((value) => {
                const expiryDate = new Date(value);
                const today = new Date();
                today.setHours(0, 0, 0, 0);

                if (expiryDate <= today) {
                    throw new Error('La data di scadenza deve essere futura');
                }
                return true;
            })
    ],
    async (req, res) => {
        try {
            // Validazione
            const errors = validationResult(req);
            if (!errors.isEmpty()) {
                return res.status(400).json({
                    success: false,
                    errors: errors.array()
                });
            }

            // Verifica che il file sia stato caricato
            if (!req.file) {
                return res.status(400).json({
                    success: false,
                    message: 'Nessun file caricato. Seleziona un file o scatta una foto.'
                });
            }

            const { taxCode, emailConfirm, expiryDate } = req.body;

            // Prima cerca l'handler per verificare l'email
            const handler = await certificatiService.findHandlerByTaxCode(taxCode);

            if (!handler) {
                return res.status(404).json({
                    success: false,
                    message: 'Socio non trovato. Verifica il codice fiscale inserito.'
                });
            }

            // Verifica che l'email corrisponda (case-insensitive)
            console.log('=== DEBUG EMAIL VALIDATION ===');
            console.log('Handler email from DB:', handler.email);
            console.log('Email confirm from form:', emailConfirm);
            
            if (!handler.email || typeof handler.email !== 'string') {
                return res.status(400).json({
                    success: false,
                    message: 'Per questo socio non risulta un indirizzo email valido. Contatta la segreteria per aggiornare i dati prima di caricare il certificato.'
                });
            }

            console.log('Handler email (lowercase):', handler.email.toLowerCase());
            console.log('Email confirm (lowercase):', emailConfirm.toLowerCase());
            console.log('Handler email length:', handler.email.length);
            console.log('Email confirm length:', emailConfirm.length);
            console.log('Comparison result:', handler.email.toLowerCase() === emailConfirm.toLowerCase());
            console.log('Handler email bytes:', Buffer.from(handler.email).toString('hex'));
            console.log('Email confirm bytes:', Buffer.from(emailConfirm).toString('hex'));
            console.log('==============================');

            if (handler.email.toLowerCase() !== emailConfirm.toLowerCase()) {
                return res.status(403).json({
                    success: false,
                    message: 'L\'email inserita non corrisponde a quella associata al codice fiscale. Verifica e riprova.'
                });
            }

            // Prepara i dati per il servizio
            const certificateData = {
                taxCode: taxCode,
                expiryDate: new Date(expiryDate),
                fileBuffer: req.file.buffer,
                fileName: getSafeCertificateFileName(req.file),
                mimeType: req.file.mimetype
            };

            // Elabora l'upload
            const result = await certificatiService.processCertificateUpload(certificateData);

            // Aggiorna la data di scadenza nel Google Sheet "Soci" (colonna AP)
            try {
                await certificatiService.updateCertificateExpiryInGoogleSheet(
                    taxCode,
                    new Date(expiryDate)
                );
            } catch (sheetError) {
                console.error('Errore aggiornamento Google Sheet (non blocca il processo):', sheetError);
                // Non bloccare il processo se l'aggiornamento del Google Sheet fallisce
            }

            // Invia email solo se non è disabilitato (ambiente di test)
            const disableEmailSending = process.env.DISABLE_EMAIL_SENDING === 'true';

            if (!disableEmailSending) {
                // Invia email di conferma al socio
                if (result.handler.email) {
                    try {
                        await sendConfirmationEmailToHandler(result.handler, result.file, expiryDate);
                    } catch (emailError) {
                        console.error('Errore nell\'invio email al socio:', emailError);
                        // Non bloccare la risposta se l'email fallisce
                    }
                }

                // Invia email di notifica all'amministrazione con URL temporaneo
                try {
                    // Genera URL temporaneo (valido 1 ora) per l'email all'admin
                    const temporaryUrl = await certificatiService.generateTemporaryUrl(result.file.path);
                    await sendNotificationEmailToAdmin(result.handler, result.file, expiryDate, temporaryUrl);
                } catch (emailError) {
                    console.error('Errore nell\'invio email all\'admin:', emailError);
                    // Non bloccare la risposta se l'email fallisce
                }
            } else {
                console.log('📧 MODALITÀ TEST: Invio email disabilitato (DISABLE_EMAIL_SENDING=true)');
                console.log(`   Email socio: ${result.handler.email}`);
                console.log(`   Email admin: ${process.env.EMAIL_CERTIFICATO_ADMIN || process.env.EMAIL_TO}`);
            }

            res.json({
                success: true,
                message: 'Certificato medico caricato con successo',
                handler: result.handler,
                file: {
                    path: result.file.path,
                    fileName: result.file.fileName
                }
            });
        } catch (error) {
            console.error('Errore nell\'upload del certificato:', error);
            res.status(500).json({
                success: false,
                message: error.message || 'Errore nell\'upload del certificato'
            });
        }
    }
);

/**
 * Invia email di conferma al socio
 */
async function sendConfirmationEmailToHandler(handler, file, expiryDate) {
    const formattedDate = new Date(expiryDate).toLocaleDateString('it-IT', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric'
    });

    const emailHtml = `
        <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background-color: #4CAF50; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
                    .content { background-color: #f9f9f9; padding: 20px; border-radius: 0 0 5px 5px; }
                    .info-row { margin: 10px 0; }
                    .label { font-weight: bold; color: #555; }
                    .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #777; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h2>Certificato Medico Ricevuto</h2>
                    </div>
                    <div class="content">
                        <p>Gentile <strong>${handler.firstName} ${handler.lastName}</strong>,</p>

                        <p>Il tuo certificato medico è stato ricevuto correttamente.</p>

                        <div class="info-row">
                            <span class="label">Data di scadenza:</span> ${formattedDate}
                        </div>

                        <div class="info-row">
                            <span class="label">Nome file:</span> ${file.fileName}
                        </div>

                        <p style="margin-top: 20px;">La segreteria provvederà alla verifica del documento.</p>

                        <p>Grazie per la collaborazione!</p>

                        <div style="background-color: #fff3cd; padding: 15px; border-left: 3px solid #ffc107; margin: 20px 0;">
                            <p style="margin: 5px 0;"><strong>⚠️ Nota:</strong> Questa è una email automatica inviata da un indirizzo non monitorato.</p>
                            <p style="margin: 5px 0;">Per qualsiasi informazione o domanda, scrivi a: <a href="mailto:laboratrieste@gmail.com" style="color: #2c5aa0; font-weight: bold;">laboratrieste@gmail.com</a></p>
                        </div>

                        <div class="footer">
                            <p><strong>Agility Club LaBora</strong></p>
                        </div>
                    </div>
                </div>
            </body>
        </html>
    `;

    await emailService.sendEmail({
        to: handler.email,
        subject: 'Certificato Medico Ricevuto - Agility Club LaBora',
        html: emailHtml
    });

    console.log(`Email di conferma inviata a: ${handler.email}`);
}

/**
 * Invia email di notifica all'amministrazione con link temporaneo al file
 * @param {Object} handler - Dati del socio
 * @param {Object} file - Informazioni sul file (path, fileName)
 * @param {Date} expiryDate - Data di scadenza del certificato
 * @param {string} temporaryUrl - URL firmato temporaneo (valido 1 ora)
 */
async function sendNotificationEmailToAdmin(handler, file, expiryDate, temporaryUrl) {
    const formattedDate = new Date(expiryDate).toLocaleDateString('it-IT', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric'
    });

    const emailHtml = `
        <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 700px; margin: 0 auto; padding: 20px; }
                    .header { background-color: #2196F3; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
                    .content { background-color: #f9f9f9; padding: 20px; border-radius: 0 0 5px 5px; }
                    .info-box { background-color: white; padding: 15px; margin: 15px 0; border-left: 4px solid #2196F3; }
                    .info-row { margin: 8px 0; }
                    .label { font-weight: bold; color: #555; display: inline-block; width: 180px; }
                    .value { color: #333; }
                    .file-link { display: inline-block; margin-top: 15px; padding: 10px 20px; background-color: #4CAF50; color: white; text-decoration: none; border-radius: 4px; }
                    .file-link:hover { background-color: #45a049; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h2>Nuovo Certificato Medico Caricato</h2>
                    </div>
                    <div class="content">
                        <p>È stato caricato un nuovo certificato medico.</p>

                        <div class="info-box">
                            <h3 style="margin-top: 0;">Dati del Socio</h3>
                            <div class="info-row">
                                <span class="label">Nome:</span>
                                <span class="value">${handler.firstName} ${handler.lastName}</span>
                            </div>
                            <div class="info-row">
                                <span class="label">Codice Fiscale:</span>
                                <span class="value">${handler.taxCode}</span>
                            </div>
                            <div class="info-row">
                                <span class="label">Email:</span>
                                <span class="value">${handler.email}</span>
                            </div>
                        </div>

                        <div class="info-box">
                            <h3 style="margin-top: 0;">Dettagli Certificato</h3>
                            <div class="info-row">
                                <span class="label">Data di scadenza:</span>
                                <span class="value"><strong>${formattedDate}</strong></span>
                            </div>
                            <div class="info-row">
                                <span class="label">Nome file:</span>
                                <span class="value">${file.fileName}</span>
                            </div>
                            <div class="info-row">
                                <span class="label">Path Storage:</span>
                                <span class="value" style="font-size: 11px; word-break: break-all;">${file.path}</span>
                            </div>
                        </div>

                        <div style="text-align: center; margin-top: 25px;">
                            <a href="${temporaryUrl}" class="file-link" target="_blank">
                                Visualizza Certificato
                            </a>
                        </div>

                        <p style="margin-top: 25px; font-size: 13px; color: #e67e22; background-color: #fef5e7; padding: 10px; border-radius: 4px; border-left: 4px solid #e67e22;">
                            <strong>⚠️ Attenzione:</strong> Il link al certificato è valido per <strong>1 ora</strong> dalla ricezione di questa email. Dopo la scadenza, il file rimarrà disponibile su Firebase Storage al path indicato sopra.
                        </p>
                    </div>
                </div>
            </body>
        </html>
    `;

    // Invia all'amministrazione - supporta email multiple separate da virgola
    const adminEmailsString = process.env.EMAIL_CERTIFICATO_ADMIN || process.env.EMAIL_TO;
    const adminEmailsArray = (adminEmailsString || '')
        .split(',')
        .map(email => email.trim())
        .filter(Boolean);

    if (adminEmailsArray.length === 0) {
        throw new Error('Destinatari admin certificato non configurati (EMAIL_CERTIFICATO_ADMIN o EMAIL_TO)');
    }

    await emailService.sendEmail({
        to: adminEmailsArray,
        subject: `Nuovo Certificato Medico - ${handler.firstName} ${handler.lastName}`,
        html: emailHtml
    });

    console.log(`Email di notifica inviata all'amministrazione: ${adminEmailsArray.join(', ')}`);
}

module.exports = router;
