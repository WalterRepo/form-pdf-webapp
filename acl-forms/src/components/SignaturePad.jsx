import React, { forwardRef, useEffect, useImperativeHandle, useRef, useState } from 'react';

export const SignaturePad = forwardRef(function SignaturePad(_, ref) {
  const canvasRef = useRef(null);
  const drawingRef = useRef(false);
  const emptyRef = useRef(true);
  const [isEmpty, setIsEmpty] = useState(true);

  const prepareCanvas = () => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const snapshot = emptyRef.current ? null : canvas.toDataURL('image/png');
    const rect = canvas.getBoundingClientRect();
    const ratio = Math.max(window.devicePixelRatio || 1, 1);
    canvas.width = Math.max(1, Math.round(rect.width * ratio));
    canvas.height = Math.max(1, Math.round(rect.height * ratio));
    const context = canvas.getContext('2d');
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
    context.lineCap = 'round';
    context.lineJoin = 'round';
    context.lineWidth = 2.2;
    context.strokeStyle = '#14213d';
    if (snapshot) {
      const image = new Image();
      image.onload = () => context.drawImage(image, 0, 0, rect.width, rect.height);
      image.src = snapshot;
    }
  };

  useEffect(() => {
    prepareCanvas();
    const observer = new ResizeObserver(prepareCanvas);
    observer.observe(canvasRef.current);
    return () => observer.disconnect();
  }, []);

  const point = (event) => {
    const rect = canvasRef.current.getBoundingClientRect();
    return { x: event.clientX - rect.left, y: event.clientY - rect.top };
  };

  const start = (event) => {
    event.preventDefault();
    canvasRef.current.setPointerCapture(event.pointerId);
    const context = canvasRef.current.getContext('2d');
    const current = point(event);
    context.beginPath();
    context.moveTo(current.x, current.y);
    drawingRef.current = true;
  };

  const move = (event) => {
    if (!drawingRef.current) return;
    event.preventDefault();
    const context = canvasRef.current.getContext('2d');
    const current = point(event);
    context.lineTo(current.x, current.y);
    context.stroke();
    emptyRef.current = false;
    setIsEmpty(false);
  };

  const stop = () => {
    drawingRef.current = false;
  };

  const clear = () => {
    const canvas = canvasRef.current;
    const context = canvas.getContext('2d');
    context.save();
    context.setTransform(1, 0, 0, 1, 0, 0);
    context.clearRect(0, 0, canvas.width, canvas.height);
    context.restore();
    emptyRef.current = true;
    setIsEmpty(true);
  };

  useImperativeHandle(ref, () => ({
    clear,
    isEmpty: () => emptyRef.current,
    toDataURL: () => emptyRef.current ? '' : canvasRef.current.toDataURL('image/png')
  }));

  return (
    <div className="acl-signature">
      <canvas
        ref={canvasRef}
        aria-label="Area per la firma elettronica"
        onPointerDown={start}
        onPointerMove={move}
        onPointerUp={stop}
        onPointerCancel={stop}
        onPointerLeave={stop}
      />
      {isEmpty && <span className="acl-signature__hint">Firma qui con il mouse o con il dito</span>}
      <div className="acl-signature__actions">
        <button type="button" className="acl-button acl-button--secondary" onClick={clear}>Cancella firma</button>
        <span>{isEmpty ? 'Firma da apporre' : 'Firma acquisita'}</span>
      </div>
    </div>
  );
});
