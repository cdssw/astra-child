import chardet
import pandas as pd

file_path = "camp_all.csv" # 파일 경로를 입력하세요.

with open(file_path, 'rb') as f:
    raw_data = f.read()
    result = chardet.detect(raw_data)
    file_encoding = result['encoding']
    confidence = result['confidence']

print(f"파일 인코딩: {file_encoding} (신뢰도: {confidence:.2f})")


df = pd.read_csv(file_path, encoding="EUC-KR")
df.to_csv(file_path, index=False, encoding="utf-8-sig")