const admin = require('firebase-admin');
const { Timestamp } = require('@google-cloud/firestore');
const { initializeFirebaseAdmin } = require('./firebaseAuthService');
const { google } = require('googleapis');

// Inizializza Firebase Admin
initializeFirebaseAdmin();

const db = admin.firestore();
const bucket = admin.storage().bucket();

// Inizializza Google Sheets
const auth = new google.auth.GoogleAuth({
    credentials: {
        client_email: process.env.GOOGLE_SERVICE_ACCOUNT_EMAIL,
        private_key: process.env.GOOGLE_PRIVATE_KEY.replace(/\\n/g, '\n')
    },
    scopes: ['https://www.googleapis.com/auth/spreadsheets']
});
const sheets = google.sheets({ version: 'v4', auth });

function getCertificateExtension(originalName, mimeType) {
    if (typeof originalName === 'string' && originalName.includes('.')) {
        return originalName.split('.').pop().toLowerCase();
    }

    const extensionByMimeType = {
        'application/pdf': 'pdf',
        'image/jpeg': 'jpg',
        'image/jpg': 'jpg',
        'image/png': 'png',
        'image/heic': 'heic',
        'image/heif': 'heif'
    };

    return extensionByMimeType[mimeType] || 'bin';
}

/**
 * Cerca un handler nel database Firestore tramite codice fiscale
 * @param {string} taxCode - Codice fiscale dell'handler
 * @returns {Promise<Object|null>} - Handler trovato o null
 */
async function findHandlerByTaxCode(taxCode) {
    try {
        const normalizedTaxCode = taxCode.trim().toUpperCase();

        const handlersRef = db.collection('handlers');
        const snapshot = await handlersRef.where('taxCode', '==', normalizedTaxCode).get();

        if (snapshot.empty) {
            console.log(`Nessun handler trovato con codice fiscale: ${normalizedTaxCode}`);
            return null;
        }

        // Prendi il primo risultato (il codice fiscale dovrebbe essere univoco)
        const handlerDoc = snapshot.docs[0];
        return {
            id: handlerDoc.id,
            ...handlerDoc.data()
        };
    } catch (error) {
        console.error('Errore nella ricerca handler per codice fiscale:', error);
        throw new Error('Errore nella ricerca del socio nel database');
    }
}

/**
 * Aggiorna la data di scadenza e il path del certificato medico di un handler
 * NON salva l'URL per motivi di sicurezza - l'URL verrà generato on-demand
 * @param {string} handlerId - ID del documento handler in Firestore
 * @param {Date} expiryDate - Data di scadenza del certificato medico
 * @param {string} certificatePath - Path del file su Firebase Storage
 * @returns {Promise<void>}
 */
async function updateMedicalCertificateExpiry(handlerId, expiryDate, certificatePath) {
    try {
        const handlerRef = db.collection('handlers').doc(handlerId);

        const updateData = {
            medicalCertificateExpiry: Timestamp.fromDate(expiryDate),
            updatedAt: Timestamp.now()
        };

        // Salva solo il path, non l'URL
        if (certificatePath) {
            updateData.medicalCertificatePath = certificatePath;
        }

        await handlerRef.update(updateData);

        console.log(`Certificato medico aggiornato per handler ${handlerId} (path: ${certificatePath})`);
    } catch (error) {
        console.error('Errore nell\'aggiornamento del certificato medico:', error);
        throw new Error('Errore nell\'aggiornamento del database');
    }
}

/**
 * Carica un file (certificato medico) su Firebase Storage
 * Il nome del file include la data di scadenza per facilitare la gestione manuale
 * Formato: certificati-medici/{taxCode}_{expiryDate}_{timestamp}.{ext}
 *
 * @param {Buffer} fileBuffer - Buffer del file da caricare
 * @param {string} originalName - Nome originale del file
 * @param {string} mimeType - Tipo MIME del file
 * @param {string} taxCode - Codice fiscale dell'handler
 * @param {Date} expiryDate - Data di scadenza del certificato
 * @returns {Promise<Object>} - Oggetto con url pubblico e path del file
 */
async function uploadCertificateToStorage(fileBuffer, originalName, mimeType, taxCode, expiryDate) {
    try {
        // Estrai l'estensione dal file originale
        const extension = getCertificateExtension(originalName, mimeType);

        // Formatta la data di scadenza per il nome del file (YYYY-MM-DD)
        const formattedExpiryDate = expiryDate.toISOString().split('T')[0];

        // Crea un timestamp unico
        const timestamp = Date.now();

        // Normalizza il codice fiscale
        const normalizedTaxCode = taxCode.trim().toUpperCase();

        // Crea il nome del file con la data di scadenza inclusa
        const fileName = `certificati-medici/${normalizedTaxCode}_scadenza-${formattedExpiryDate}_${timestamp}.${extension}`;

        // Crea il riferimento al file
        const file = bucket.file(fileName);

        // Carica il file
        await file.save(fileBuffer, {
            metadata: {
                contentType: mimeType,
                metadata: {
                    taxCode: normalizedTaxCode,
                    expiryDate: formattedExpiryDate,
                    uploadDate: new Date().toISOString(),
                    originalName: originalName || `certificato.${extension}`
                }
            }
        });

        // Rendi il file pubblicamente accessibile (o genera un signed URL)
        // Per ora generiamo un signed URL valido per 10 anni
        const [url] = await file.getSignedUrl({
            action: 'read',
            expires: Date.now() + 10 * 365 * 24 * 60 * 60 * 1000 // 10 anni
        });

        console.log(`File caricato su Storage: ${fileName}`);

        return {
            url: url,
            path: fileName,
            fileName: fileName
        };
    } catch (error) {
        console.error('Errore nel caricamento del file su Storage:', error);
        throw new Error('Errore nel caricamento del file');
    }
}

/**
 * Elabora l'upload completo di un certificato medico:
 * 1. Cerca l'handler tramite codice fiscale
 * 2. Carica il file su Firebase Storage
 * 3. Aggiorna la data di scadenza nel database
 *
 * @param {Object} certificateData - Dati del certificato
 * @param {string} certificateData.taxCode - Codice fiscale
 * @param {Date} certificateData.expiryDate - Data di scadenza
 * @param {Buffer} certificateData.fileBuffer - Buffer del file
 * @param {string} certificateData.fileName - Nome originale del file
 * @param {string} certificateData.mimeType - Tipo MIME
 * @returns {Promise<Object>} - Risultato dell'operazione
 */
async function processCertificateUpload(certificateData) {
    try {
        const { taxCode, expiryDate, fileBuffer, fileName, mimeType } = certificateData;

        // 1. Cerca l'handler
        const handler = await findHandlerByTaxCode(taxCode);

        if (!handler) {
            throw new Error('Socio non trovato. Verifica il codice fiscale inserito.');
        }

        // 2. Carica il file su Storage
        const uploadResult = await uploadCertificateToStorage(
            fileBuffer,
            fileName,
            mimeType,
            taxCode,
            expiryDate
        );

        // 3. Aggiorna il database con data di scadenza e path del file (non l'URL per sicurezza)
        await updateMedicalCertificateExpiry(
            handler.id,
            expiryDate,
            uploadResult.path
        );

        return {
            success: true,
            handler: {
                id: handler.id,
                firstName: handler.firstName,
                lastName: handler.lastName,
                email: handler.email,
                taxCode: handler.taxCode
            },
            file: uploadResult,
            message: 'Certificato medico caricato con successo'
        };
    } catch (error) {
        console.error('Errore nell\'elaborazione del certificato:', error);
        throw error;
    }
}

/**
 * Genera un URL firmato temporaneo per accedere a un certificato medico
 * L'URL è valido per 1 ora
 * @param {string} filePath - Path del file su Firebase Storage
 * @returns {Promise<string>} - URL firmato temporaneo
 */
async function generateTemporaryUrl(filePath) {
    try {
        const file = bucket.file(filePath);

        // Genera URL firmato valido per 1 ora
        const [url] = await file.getSignedUrl({
            action: 'read',
            expires: Date.now() + 60 * 60 * 1000 // 1 ora
        });

        console.log(`URL temporaneo generato per: ${filePath} (valido per 1 ora)`);
        return url;
    } catch (error) {
        console.error('Errore nella generazione URL temporaneo:', error);
        throw new Error('Errore nella generazione del link di accesso al file');
    }
}

/**
 * Aggiorna la data di scadenza del certificato medico nel Google Sheet "Soci"
 * Cerca il socio tramite codice fiscale e aggiorna la colonna AP
 * @param {string} taxCode - Codice fiscale dell'handler
 * @param {Date} expiryDate - Data di scadenza del certificato
 * @returns {Promise<boolean>} - True se l'aggiornamento ha avuto successo
 */
async function updateCertificateExpiryInGoogleSheet(taxCode, expiryDate) {
    try {
        const spreadsheetId = process.env.GOOGLE_SHEETS_ID;
        const normalizedTaxCode = taxCode.trim().toUpperCase();

        // 1. Leggi tutte le righe del foglio "Soci" dalla colonna A alla AP
        const response = await sheets.spreadsheets.values.get({
            spreadsheetId,
            range: 'Soci!A2:AP' // Dalla riga 2 in poi (escluso header)
        });

        const rows = response.data.values || [];

        // 2. Cerca la riga con il codice fiscale corrispondente
        // Il codice fiscale è nella colonna K (indice 10)
        let rowIndex = -1;
        for (let i = 0; i < rows.length; i++) {
            const cf = rows[i][10] ? rows[i][10].toString().trim().toUpperCase() : '';
            if (cf === normalizedTaxCode) {
                rowIndex = i + 2; // +2 perché partiamo da riga 2 (header è riga 1)
                break;
            }
        }

        if (rowIndex === -1) {
            console.log(`⚠️ Socio con CF ${normalizedTaxCode} non trovato nel foglio "Soci"`);
            return false;
        }

        // 3. Aggiorna la colonna AP (indice 41, colonna 42) con la data di scadenza
        // Formatta la data in formato italiano (gg/mm/aaaa)
        const formattedDate = expiryDate.toLocaleDateString('it-IT', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric'
        });

        const updateRange = `Soci!AP${rowIndex}`;
        await sheets.spreadsheets.values.update({
            spreadsheetId,
            range: updateRange,
            valueInputOption: 'USER_ENTERED',
            resource: {
                values: [[formattedDate]]
            }
        });

        console.log(`✅ Scadenza certificato aggiornata nel Google Sheet "Soci" (riga ${rowIndex}, colonna AP): ${formattedDate}`);
        return true;

    } catch (error) {
        console.error('❌ Errore nell\'aggiornamento Google Sheet "Soci":', error);
        // Non lanciare l'errore per non bloccare il processo principale
        // L'aggiornamento del Google Sheet è secondario rispetto al caricamento del file
        return false;
    }
}

module.exports = {
    findHandlerByTaxCode,
    updateMedicalCertificateExpiry,
    uploadCertificateToStorage,
    processCertificateUpload,
    generateTemporaryUrl,
    updateCertificateExpiryInGoogleSheet
};
