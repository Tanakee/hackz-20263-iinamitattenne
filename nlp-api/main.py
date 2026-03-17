import spacy
from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from scale_dict import SCALE_DICT, MODIFIER_BONUS, DEFAULT_SCALE

app = FastAPI()
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)

nlp = spacy.load("ja_ginza")


class TextRequest(BaseModel):
    text: str


@app.get("/health")
def health():
    return {"status": "ok", "engine": "ginza"}


@app.post("/analyze-subject")
def analyze_subject(req: TextRequest):
    doc = nlp(req.text)
    subjects = []
    all_tokens = []
    for sent in doc.sents:
        for token in sent:
            all_tokens.append({
                "text": token.text,
                "dep": token.dep_,
                "pos": token.pos_,
                "head": token.head.text,
                "lemma": token.lemma_,
            })
            if token.dep_ in ("nsubj", "dislocated"):
                subjects.append({
                    "text": token.text,
                    "lemma": token.lemma_,
                    "pos": token.pos_,
                    "dep": token.dep_,
                    "head": token.head.text,
                })
    return {"subjects": subjects, "all_tokens": all_tokens, "text": req.text}


@app.post("/subject-scale")
def subject_scale(req: TextRequest):
    doc = nlp(req.text)
    subjects = []
    max_scale = 0

    for sent in doc.sents:
        has_dislocated = any(t.dep_ == "dislocated" for t in sent)
        for token in sent:
            # nsubj と dislocated（は-トピック）の両方を主語として扱う
            if token.dep_ not in ("nsubj", "dislocated"):
                continue
            # dislocatedがある場合、nsubjは補語扱い（「僕はラーメンが好き」のラーメン）
            if has_dislocated and token.dep_ == "nsubj":
                continue

            word = token.text
            lemma = token.lemma_

            # 辞書から検索（表層形 → 原形の順）
            scale = SCALE_DICT.get(word, SCALE_DICT.get(lemma, DEFAULT_SCALE))

            # 固有表現ボーナス
            if token.ent_type_ in ("GPE", "LOC", "FAC"):
                scale = max(scale, 70)
            elif token.ent_type_ == "ORG":
                scale = max(scale, 55)
            elif token.ent_type_ == "PERSON":
                scale = max(scale, 20)

            # 修飾語ボーナス
            for child in token.children:
                bonus = MODIFIER_BONUS.get(child.text, 0)
                scale += bonus

            scale = max(0, min(100, scale))
            subjects.append({"text": word, "scale": scale})
            max_scale = max(max_scale, scale)

    if not subjects:
        max_scale = DEFAULT_SCALE

    return {
        "subjects": subjects,
        "max_scale": max_scale,
        "text": req.text,
    }
