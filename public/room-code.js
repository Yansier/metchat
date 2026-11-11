(() => {
  "use strict";

  const ALPHABET = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-";
  const DEFAULT_LENGTH = 24;

  function generateRoomCode(length = DEFAULT_LENGTH) {
    if (!Number.isInteger(length) || length < 8 || length > 64) {
      throw new RangeError("Room code length must be an integer between 8 and 64.");
    }
    if (!window.crypto || typeof window.crypto.getRandomValues !== "function") {
      throw new Error("Secure random generation is unavailable.");
    }

    // Rejection sampling keeps this unbiased even if the alphabet changes to
    // a size that does not divide the 256 possible byte values.
    const unbiasedLimit = 256 - (256 % ALPHABET.length);
    let result = "";
    while (result.length < length) {
      const bytes = new Uint8Array(Math.max(32, (length - result.length) * 2));
      window.crypto.getRandomValues(bytes);
      for (const value of bytes) {
        if (value < unbiasedLimit) {
          result += ALPHABET[value % ALPHABET.length];
          if (result.length === length) {
            break;
          }
        }
      }
    }

    return result;
  }

  window.MetChatRoomCode = Object.freeze({
    alphabet: ALPHABET,
    defaultLength: DEFAULT_LENGTH,
    generate: generateRoomCode,
  });
})();
